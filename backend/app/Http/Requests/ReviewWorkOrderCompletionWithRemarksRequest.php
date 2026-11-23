<?php

namespace App\Http\Requests;

use App\Enums\WorkOrderStatus;
use App\Models\WorkOrder;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

/**
 * The current Site EIC returns (for rework) or rejects the completed work. Remarks are required. Checks run in this
 * order: `view` (plain 403), the acting EIC (403), the Implemented status (422, reported alone), then the remarks.
 */
class ReviewWorkOrderCompletionWithRemarksRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return Gate::allows('view', $this->workOrder()) && Gate::allows('validateCompletion', $this->workOrder());
    }

    /**
     * Get the validation rules that apply to the request. None while the Work Order isn't Implemented.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return $this->isImplemented() ? ['remarks' => ['required', 'string', 'max:5000']] : [];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'remarks.required' => 'Remarks are required when returning or rejecting completed work.',
        ];
    }

    /**
     * @return array<int, Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! $this->isImplemented()) {
                    $validator->errors()->add('work_order', WorkOrder::notImplemented()->errors()['work_order'][0]);
                }
            },
        ];
    }

    public function workOrder(): WorkOrder
    {
        /** @var WorkOrder */
        return $this->route('workOrder');
    }

    private function isImplemented(): bool
    {
        return $this->workOrder()->getRawOriginal('status') === WorkOrderStatus::Implemented->value;
    }
}
