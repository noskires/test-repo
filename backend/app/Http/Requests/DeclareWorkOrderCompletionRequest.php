<?php

namespace App\Http\Requests;

use App\Billing\BoqQuantity;
use App\Enums\WorkOrderStatus;
use App\Models\WorkOrder;
use App\Models\WorkOrderLine;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use InvalidArgumentException;

/**
 * The Site Vendor declares a Work Order complete (issue 20). Checks run in this order: `view` (plain 403, no status
 * oracle), the Site Vendor's staff (403), the For Implementation status (422, reported alone), then the payload (422):
 * an actual quantity >= 0 for every Work Order line exactly once, at least one completion document, optional photos
 * and notes. The Vendor and status are re-checked under a lock when it is stored (WorkOrderCompletion::submit).
 */
class DeclareWorkOrderCompletionRequest extends FormRequest
{
    private const array PHOTO_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    /** @var Collection<int, WorkOrderLine>|null */
    private ?Collection $workOrderLines = null;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return Gate::allows('view', $this->workOrder()) && Gate::allows('declareCompletion', $this->workOrder());
    }

    /**
     * Get the validation rules that apply to the request. None while the Work Order isn't For Implementation, so
     * that status error is reported on its own (see after()).
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        if (! $this->isForImplementation()) {
            return [];
        }

        $extensions = implode(',', config('pss.e_call_attachments.extensions'));
        $photoExtensions = implode(',', self::PHOTO_EXTENSIONS);
        $maxKilobytes = config('pss.e_call_attachments.max_kilobytes');
        $maxFiles = config('pss.e_call_attachments.max_files');

        return [
            'lines' => ['required', 'array', 'min:1'],
            'lines.*' => ['array:work_order_line_id,actual_quantity'],
            'lines.*.work_order_line_id' => [
                'bail',
                'required',
                'integer',
                'distinct',
                Rule::exists('work_order_lines', 'id')->where('work_order_id', $this->workOrder()->id),
            ],
            'lines.*.actual_quantity' => ['bail', 'required', $this->quantityRule()],
            'documents' => ['required', 'array', 'min:1', 'max:'.$maxFiles],
            'documents.*' => ['file', 'mimes:'.$extensions, 'extensions:'.$extensions, 'max:'.$maxKilobytes],
            'photos' => ['sometimes', 'array', 'max:'.$maxFiles],
            'photos.*' => ['file', 'mimes:'.$photoExtensions, 'extensions:'.$photoExtensions, 'max:'.$maxKilobytes],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'lines.*.work_order_line_id.exists' => 'The selected line is not on this Work Order.',
            'lines.*.work_order_line_id.distinct' => 'Each Work Order line may be reported only once.',
            'documents.required' => 'At least one completion document is required.',
        ];
    }

    /**
     * The status error (alone), or every Work Order line not reported.
     *
     * @return array<int, Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! $this->isForImplementation()) {
                    $validator->errors()->add('work_order', 'Completion can only be declared while the Work Order is For Implementation.');

                    return;
                }

                if ($validator->errors()->has('lines')) {
                    return;
                }

                $reported = collect($this->input('lines', []))->pluck('work_order_line_id')->map(fn (mixed $id): int => (int) $id)->all();
                $missing = $this->workOrderLines()->reject(fn (WorkOrderLine $line): bool => in_array($line->id, $reported, true));

                if ($missing->isNotEmpty()) {
                    $validator->errors()->add('lines', 'Every Work Order line needs an actual quantity. Missing: '.$missing->pluck('description')->implode(', ').'.');
                }
            },
        ];
    }

    public function workOrder(): WorkOrder
    {
        /** @var WorkOrder */
        return $this->route('workOrder');
    }

    /**
     * @return list<array{line: WorkOrderLine, quantity_thousandths: int}>
     */
    public function completionLines(): array
    {
        return array_map(fn (array $line): array => [
            'line' => $this->workOrderLines()->get((int) $line['work_order_line_id']),
            'quantity_thousandths' => BoqQuantity::toThousandths($line['actual_quantity'], $this->quantityDecimalPlaces()),
        ], array_values($this->validated('lines')));
    }

    /**
     * @return list<UploadedFile>
     */
    public function documents(): array
    {
        return array_values($this->file('documents', []));
    }

    /**
     * @return list<UploadedFile>
     */
    public function photos(): array
    {
        return array_values($this->file('photos', []));
    }

    private function isForImplementation(): bool
    {
        return $this->workOrder()->getRawOriginal('status') === WorkOrderStatus::ForImplementation->value;
    }

    private function quantityRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $decimalPlaces = $this->quantityDecimalPlaces();

            try {
                if (is_string($value) || is_int($value) || is_float($value)) {
                    BoqQuantity::toThousandths($value, $decimalPlaces);

                    return;
                }
            } catch (InvalidArgumentException) {
                // Falls through to the failure below.
            }

            $fail("The actual quantity must be a number of zero or more with at most {$decimalPlaces} decimal places.");
        };
    }

    /**
     * @return Collection<int, WorkOrderLine>
     */
    private function workOrderLines(): Collection
    {
        return $this->workOrderLines ??= $this->workOrder()->lines()->get()->keyBy('id');
    }

    private function quantityDecimalPlaces(): int
    {
        return (int) config('pss.boq.quantity_decimal_places');
    }
}
