<?php

namespace App\Http\Requests;

use App\Billing\BoqQuantity;
use App\Enums\WorkOrderStatus;
use App\Models\WorkOrder;
use App\Models\WorkOrderLine;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use InvalidArgumentException;

/**
 * The current Site EIC validates the completed work (issue 21): a validated quantity >= 0 (3 decimals, may exceed the
 * approved one) for every Work Order line exactly once, and optional remarks. Checks run in this order: `view`
 * (plain 403), the acting EIC (403), the Implemented status (422, reported alone), then the payload (422). The EIC,
 * status and billing chain are re-checked under a lock when it is stored (WorkOrder::validateCompletion).
 */
class ValidateWorkOrderCompletionRequest extends FormRequest
{
    /** @var Collection<int, WorkOrderLine>|null */
    private ?Collection $workOrderLines = null;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return Gate::allows('view', $this->workOrder()) && Gate::allows('validateCompletion', $this->workOrder());
    }

    /**
     * Get the validation rules that apply to the request. None while the Work Order isn't Implemented, so that
     * status error is reported on its own (see after()).
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        if (! $this->isImplemented()) {
            return [];
        }

        return [
            'lines' => ['required', 'array', 'min:1'],
            'lines.*' => ['array:work_order_line_id,validated_quantity'],
            'lines.*.work_order_line_id' => [
                'bail',
                'required',
                'integer',
                'distinct',
                Rule::exists('work_order_lines', 'id')->where('work_order_id', $this->workOrder()->id),
            ],
            'lines.*.validated_quantity' => ['bail', 'required', $this->quantityRule()],
            'remarks' => ['nullable', 'string', 'max:5000'],
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
            'lines.*.work_order_line_id.distinct' => 'Each Work Order line may be validated only once.',
        ];
    }

    /**
     * The status error (alone), or every Work Order line not validated.
     *
     * @return array<int, Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! $this->isImplemented()) {
                    $validator->errors()->add('work_order', WorkOrder::notImplemented()->errors()['work_order'][0]);

                    return;
                }

                if ($validator->errors()->has('lines')) {
                    return;
                }

                $validated = collect($this->input('lines', []))->pluck('work_order_line_id')->map(fn (mixed $id): int => (int) $id)->all();
                $missing = $this->workOrderLines()->reject(fn (WorkOrderLine $line): bool => in_array($line->id, $validated, true));

                if ($missing->isNotEmpty()) {
                    $validator->errors()->add('lines', 'Every Work Order line needs a validated quantity. Missing: '.$missing->pluck('description')->implode(', ').'.');
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
    public function validatedLines(): array
    {
        return array_map(fn (array $line): array => [
            'line' => $this->workOrderLines()->get((int) $line['work_order_line_id']),
            'quantity_thousandths' => BoqQuantity::toThousandths($line['validated_quantity'], $this->quantityDecimalPlaces()),
        ], array_values($this->validated('lines')));
    }

    public function remarks(): ?string
    {
        $remarks = $this->validated('remarks');

        return is_string($remarks) && trim($remarks) !== '' ? $remarks : null;
    }

    private function isImplemented(): bool
    {
        return $this->workOrder()->getRawOriginal('status') === WorkOrderStatus::Implemented->value;
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

            $fail("The validated quantity must be a number of zero or more with at most {$decimalPlaces} decimal places.");
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
