<?php

namespace App\Http\Requests;

use App\Billing\BillingThresholds;
use App\Billing\PesoAmount;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

class UpdateBillingThresholdsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return Gate::allows('manage-billing-thresholds');
    }

    /**
     * Get the validation rules that apply to the request. Amounts are pesos with at most two decimal places.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $pesoAmount = ['required', 'numeric', 'regex:'.PesoAmount::PATTERN, 'gt:0', 'max:999999999999.99'];

        return [
            'lower_threshold' => $pesoAmount,
            'upper_threshold' => $pesoAmount,
        ];
    }

    /**
     * Compare the thresholds exactly, in centavos, once both are individually valid.
     *
     * @return array<int, Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->hasAny(['lower_threshold', 'upper_threshold'])) {
                    return;
                }

                if (PesoAmount::toCentavos($this->input('lower_threshold')) >= PesoAmount::toCentavos($this->input('upper_threshold'))) {
                    $validator->errors()->add('lower_threshold', 'The lower threshold must be less than the upper threshold.');
                }
            },
        ];
    }

    public function billingThresholds(): BillingThresholds
    {
        return new BillingThresholds(
            PesoAmount::toCentavos($this->validated('lower_threshold')),
            PesoAmount::toCentavos($this->validated('upper_threshold')),
        );
    }
}
