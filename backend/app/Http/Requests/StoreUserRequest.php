<?php

namespace App\Http\Requests;

use App\MasterData\MasterDataDirectory;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class StoreUserRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return Gate::allows('manage-roles');
    }

    /**
     * Get the validation rules that apply to the request (issue 46, Q14). The employee must exist in the master data
     * and not be a PSS user yet. Exactly one of `access: internal` or an existing `vendor_id` classifies the new user.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'employee_id' => [
                'bail',
                'required',
                'string',
                'max:255',
                Rule::unique('users', 'employee_id'),
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (app(MasterDataDirectory::class)->findEmployee($value) === null) {
                        $fail('This employee ID is not in the master data.');
                    }
                },
            ],
            'access' => ['required_without:vendor_id', 'prohibits:vendor_id', 'nullable', Rule::in(['internal'])],
            'vendor_id' => ['required_without:access', 'nullable', 'integer', 'exists:vendors,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'employee_id.unique' => 'This employee is already a PSS user.',
            'access.required_without' => 'Choose internal or a Vendor.',
            'vendor_id.required_without' => 'Choose internal or a Vendor.',
            'access.prohibits' => 'Choose internal or a Vendor, not both.',
            'access.in' => 'The access must be internal, or send a vendor_id instead.',
        ];
    }
}
