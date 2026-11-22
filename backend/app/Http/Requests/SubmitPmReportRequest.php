<?php

namespace App\Http\Requests;

use App\Billing\BoqQuantity;
use App\Checklists\ChecklistResponseEvaluator;
use App\Enums\ChecklistResponseType;
use App\Models\ChecklistItem;
use App\Models\PmTask;
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
 * The Site Vendor's PM Report for a PM Task, validated against the checklist version the task was generated with.
 * Responses are keyed by Checklist Item ID: responses[{itemId}][value|remarks|photos][]. Errors name the item.
 * The task status is checked under a lock when the revision is stored (PmReport::submit).
 */
class SubmitPmReportRequest extends FormRequest
{
    private const array PHOTO_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    /** @var Collection<int, ChecklistItem>|null */
    private ?Collection $checklistItems = null;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return Gate::allows('work', $this->pmTask());
    }

    /**
     * Get the validation rules that apply to the request. Upload limits and the material quantity precision
     * come from config/pss.php.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $extensions = implode(',', config('pss.e_call_attachments.extensions'));
        $photoExtensions = implode(',', self::PHOTO_EXTENSIONS);
        $maxKilobytes = config('pss.e_call_attachments.max_kilobytes');
        $items = $this->checklistItems();

        $rules = [
            'work_done_date' => ['bail', 'required', 'date_format:Y-m-d', 'before_or_equal:today', $this->notBeforeStartRule()],
            'findings' => ['nullable', 'string', 'max:10000'],
            'service_report' => ['required', 'file', 'mimes:'.$extensions, 'extensions:'.$extensions, 'max:'.$maxKilobytes],
            'responses' => $items->isEmpty()
                ? ['prohibited']
                : ['sometimes', 'array:'.$items->keys()->implode(',')],
            'materials' => ['sometimes', 'array', 'max:100'],
            'materials.*' => ['array:description,quantity'],
            'materials.*.description' => ['required', 'string', 'max:255'],
            'materials.*.quantity' => ['bail', 'required', $this->materialQuantityRule()],
            'attachments' => ['sometimes', 'array', 'max:'.config('pss.e_call_attachments.max_files')],
            'attachments.*' => ['file', 'mimes:'.$extensions, 'extensions:'.$extensions, 'max:'.$maxKilobytes],
        ];

        foreach ($items as $id => $item) {
            $isPhotoItem = $item->response_type === ChecklistResponseType::Photo;
            $needsPhoto = $item->photo_required || ($isPhotoItem && $item->mandatory);

            $rules["responses.{$id}"] = ['array:value,remarks,photos'];
            $rules["responses.{$id}.value"] = $this->valueRules($item);
            $rules["responses.{$id}.remarks"] = ['nullable', 'string', 'max:5000'];
            $rules["responses.{$id}.photos"] = [$needsPhoto ? 'required' : 'sometimes', 'array', 'max:20'];
            $rules["responses.{$id}.photos.*"] = ['file', 'mimes:'.$photoExtensions, 'extensions:'.$photoExtensions, 'max:'.$maxKilobytes];
        }

        return $rules;
    }

    /**
     * Get custom messages for validator errors. Every per-item message names the item.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        $messages = [
            'work_done_date.before_or_equal' => 'The Work Done Date cannot be in the future.',
            'responses.prohibited' => 'This PM Task has no checklist, so the report takes no checklist responses.',
            'responses.array' => "Responses may only answer items of this PM Task's checklist.",
        ];

        foreach ($this->checklistItems() as $id => $item) {
            $label = "\"{$item->label}\"";

            $messages["responses.{$id}.value.required"] = "A response to {$label} is required.";
            $messages["responses.{$id}.value.in"] = "The response to {$label} must be one of: ".implode(', ', ChecklistResponseEvaluator::CHOICES[$item->response_type->value] ?? []).'.';
            $messages["responses.{$id}.value.regex"] = "The reading for {$label} must be a number.";
            $messages["responses.{$id}.value.prohibited"] = "{$label} is answered with photos, not a value.";
            $messages["responses.{$id}.photos.required"] = "{$label} needs at least one photo.";
        }

        return $messages;
    }

    /**
     * Get custom attributes for validator errors, so the remaining default messages name the item too.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        $attributes = [];

        foreach ($this->checklistItems() as $id => $item) {
            $attributes["responses.{$id}"] = "response to \"{$item->label}\"";
            $attributes["responses.{$id}.value"] = "response to \"{$item->label}\"";
            $attributes["responses.{$id}.remarks"] = "remarks for \"{$item->label}\"";
            $attributes["responses.{$id}.photos"] = "photos for \"{$item->label}\"";
            $attributes["responses.{$id}.photos.*"] = "photo for \"{$item->label}\"";
        }

        return $attributes;
    }

    /**
     * Failed items that require remarks need them. Runs after the field rules.
     *
     * @return array<int, Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                foreach ($this->checklistItems() as $id => $item) {
                    if (! $item->remarks_required_on_fail || $validator->errors()->has("responses.{$id}.value")) {
                        continue;
                    }

                    $remarks = $this->input("responses.{$id}.remarks");

                    if ($this->isFailed($item, $this->input("responses.{$id}.value")) && (! is_string($remarks) || trim($remarks) === '')) {
                        $validator->errors()->add("responses.{$id}.remarks", "Remarks are required because \"{$item->label}\" failed.");
                    }
                }
            },
        ];
    }

    public function pmTask(): PmTask
    {
        /** @var PmTask */
        return $this->route('pmTask');
    }

    /**
     * The validated responses, one per answered Checklist Item, with their failure flag and photos.
     *
     * @return list<array{item: ChecklistItem, value: ?string, remarks: ?string, is_failed: bool, photos: list<UploadedFile>}>
     */
    public function checklistResponses(): array
    {
        $responses = [];

        foreach ($this->checklistItems() as $id => $item) {
            $input = $this->validated("responses.{$id}");

            if (! is_array($input) || $input === []) {
                continue;
            }

            $value = isset($input['value']) && $input['value'] !== '' ? (string) $input['value'] : null;
            $remarks = isset($input['remarks']) && trim((string) $input['remarks']) !== '' ? (string) $input['remarks'] : null;

            $responses[] = [
                'item' => $item,
                'value' => $value,
                'remarks' => $remarks,
                'is_failed' => $this->isFailed($item, $value),
                'photos' => array_values($this->file("responses.{$id}.photos", [])),
            ];
        }

        return $responses;
    }

    /**
     * @return list<array{description: string, quantity_thousandths: int}>
     */
    public function materials(): array
    {
        return array_map(fn (array $material): array => [
            'description' => $material['description'],
            'quantity_thousandths' => BoqQuantity::toThousandths($material['quantity'], $this->quantityDecimalPlaces()),
        ], array_values($this->validated('materials', [])));
    }

    public function serviceReport(): UploadedFile
    {
        return $this->file('service_report');
    }

    /**
     * General attachments (other documents and photos), not linked to a Checklist Item. The PM Service Report
     * has its own field.
     *
     * @return list<UploadedFile>
     */
    public function generalAttachments(): array
    {
        return array_values($this->file('attachments', []));
    }

    /**
     * @return array<int, mixed>
     */
    private function valueRules(ChecklistItem $item): array
    {
        $presence = $item->mandatory ? 'required' : 'nullable';

        return match ($item->response_type) {
            ChecklistResponseType::Photo => ['prohibited'],
            ChecklistResponseType::Numeric => ['bail', $presence, 'regex:'.ChecklistResponseEvaluator::NUMERIC_READING_PATTERN],
            ChecklistResponseType::Text => [$presence, 'string', 'max:5000'],
            default => [$presence, 'string', Rule::in(ChecklistResponseEvaluator::CHOICES[$item->response_type->value])],
        };
    }

    /**
     * The Work Done Date may not be before the day the task was started, taken in the app timezone. A task
     * started before started_at existed (null) is only held to the "not in the future" rule.
     */
    private function notBeforeStartRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $startedAt = $this->pmTask()->started_at;

            if ($startedAt === null) {
                return;
            }

            $startDate = $startedAt->setTimezone(config('app.timezone'))->toDateString();

            if ($value < $startDate) {
                $fail("The Work Done Date cannot be before the PM Task was started ({$startDate}).");
            }
        };
    }

    private function materialQuantityRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $decimalPlaces = $this->quantityDecimalPlaces();

            try {
                $thousandths = is_string($value) || is_int($value) || is_float($value)
                    ? BoqQuantity::toThousandths($value, $decimalPlaces)
                    : null;
            } catch (InvalidArgumentException) {
                $thousandths = null;
            }

            if ($thousandths === null) {
                $fail("The quantity must be a number with at most {$decimalPlaces} decimal places.");
            } elseif ($thousandths === 0) {
                $fail('The quantity must be greater than zero.');
            }
        };
    }

    private function isFailed(ChecklistItem $item, mixed $value): bool
    {
        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            return false;
        }

        return ChecklistResponseEvaluator::isFailed($item->response_type, (string) $value, $item->acceptable_min, $item->acceptable_max);
    }

    /**
     * The Checklist Items of the task's checklist version, keyed by ID. Empty for a task with no checklist.
     *
     * @return Collection<int, ChecklistItem>
     */
    private function checklistItems(): Collection
    {
        return $this->checklistItems ??= $this->pmTask()->checklist_template_version_id === null
            ? new Collection
            : ChecklistItem::query()
                ->where('checklist_template_version_id', $this->pmTask()->checklist_template_version_id)
                ->orderBy('position')
                ->get()
                ->keyBy('id');
    }

    private function quantityDecimalPlaces(): int
    {
        return (int) config('pss.boq.quantity_decimal_places');
    }
}
