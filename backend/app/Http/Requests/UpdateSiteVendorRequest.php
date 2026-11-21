<?php

namespace App\Http\Requests;

use App\Enums\VendorStatus;
use App\Models\Vendor;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class UpdateSiteVendorRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return Gate::allows('manage-site-settings');
    }

    /**
     * Get the validation rules that apply to the request. Only an active Vendor can be a Site Vendor.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'vendor_id' => [
                'required',
                'integer',
                Rule::exists('vendors', 'id')->where('status', VendorStatus::Active->value),
            ],
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
            'vendor_id.exists' => 'The selected vendor does not exist or is not active.',
        ];
    }

    public function vendor(): Vendor
    {
        return Vendor::query()->findOrFail($this->validated('vendor_id'));
    }
}
