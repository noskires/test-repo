<?php

namespace App\Http\Requests;

use App\Enums\PmFrequency;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class UpdateSitePmFrequencyRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return Gate::allows('manage-site-settings');
    }

    /**
     * Get the validation rules that apply to the request. Only the fixed MVP frequencies are allowed.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'pm_frequency' => ['required', 'string', Rule::enum(PmFrequency::class)],
        ];
    }

    public function frequency(): PmFrequency
    {
        return PmFrequency::from($this->validated('pm_frequency'));
    }
}
