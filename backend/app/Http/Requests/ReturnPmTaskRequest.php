<?php

namespace App\Http\Requests;

use App\Models\PmTask;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * The EIC returns a PM Report to the vendor. Remarks are required, so the vendor knows what to fix.
 */
class ReturnPmTaskRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return Gate::allows('validate', $this->pmTask());
    }

    /**
     * Get the validation rules that apply to the request. Blank remarks are trimmed to null and rejected.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'remarks' => ['required', 'string', 'max:5000'],
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
            'remarks.required' => 'Remarks are required when returning a PM Report.',
        ];
    }

    public function pmTask(): PmTask
    {
        /** @var PmTask */
        return $this->route('pmTask');
    }
}
