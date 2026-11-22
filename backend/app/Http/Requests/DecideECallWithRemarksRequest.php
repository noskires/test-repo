<?php

namespace App\Http\Requests;

use App\Models\ECall;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * The Site's current EIC returns or rejects an E-Call's FSR and BOQ. Remarks are required (blank is rejected).
 */
class DecideECallWithRemarksRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return Gate::allows('review', $this->eCall());
    }

    /**
     * Get the validation rules that apply to the request.
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
            'remarks.required' => 'Remarks are required when returning or rejecting an E-Call.',
        ];
    }

    public function eCall(): ECall
    {
        /** @var ECall */
        return $this->route('eCall');
    }
}
