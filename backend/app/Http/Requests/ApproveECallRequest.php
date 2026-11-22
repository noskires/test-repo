<?php

namespace App\Http\Requests;

use App\Models\ECall;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * The Site's current EIC approves an E-Call's FSR and BOQ. Remarks are optional.
 */
class ApproveECallRequest extends FormRequest
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
            'remarks' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function eCall(): ECall
    {
        /** @var ECall */
        return $this->route('eCall');
    }

    public function remarks(): ?string
    {
        $remarks = $this->validated('remarks');

        return is_string($remarks) && trim($remarks) !== '' ? $remarks : null;
    }
}
