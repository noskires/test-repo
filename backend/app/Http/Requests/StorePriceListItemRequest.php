<?php

namespace App\Http\Requests;

use App\Billing\PesoAmount;
use App\Enums\PriceListItemType;
use App\Models\Vendor;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class StorePriceListItemRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return Gate::allows('manage-price-lists');
    }

    /**
     * Get the validation rules that apply to the request. The unit price is pesos (>= 0, at most two decimals).
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Vendor $vendor */
        $vendor = $this->route('vendor');

        return [
            'code' => ['nullable', 'string', 'max:50', Rule::unique('price_list_items', 'code')->where('vendor_id', $vendor->id)],
            'description' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::enum(PriceListItemType::class)],
            'unit_of_measure' => ['required', 'string', 'max:50'],
            'unit_price' => ['required', 'numeric', 'regex:'.PesoAmount::PATTERN, 'max:999999999999.99'],
        ];
    }

    /**
     * The validated attributes ready to persist, with the unit price in centavos.
     *
     * @return array<string, mixed>
     */
    public function priceListItemAttributes(): array
    {
        $attributes = $this->safe()->except('unit_price');
        $attributes['unit_price_centavos'] = PesoAmount::toCentavos($this->validated('unit_price'));

        return $attributes;
    }
}
