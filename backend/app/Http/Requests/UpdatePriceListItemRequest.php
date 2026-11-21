<?php

namespace App\Http\Requests;

use App\Billing\PesoAmount;
use App\Enums\PriceListItemType;
use App\Models\PriceListItem;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class UpdatePriceListItemRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return Gate::allows('manage-price-lists');
    }

    /**
     * Get the validation rules that apply to the request. Deactivate with is_active=false; there is no delete.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var PriceListItem $item */
        $item = $this->route('priceListItem');

        return [
            'code' => [
                'sometimes',
                'nullable',
                'string',
                'max:50',
                Rule::unique('price_list_items', 'code')->where('vendor_id', $item->vendor_id)->ignore($item),
            ],
            'description' => ['sometimes', 'required', 'string', 'max:255'],
            'type' => ['sometimes', 'required', Rule::enum(PriceListItemType::class)],
            'unit_of_measure' => ['sometimes', 'required', 'string', 'max:50'],
            'unit_price' => ['sometimes', 'required', 'numeric', 'regex:'.PesoAmount::PATTERN, 'max:999999999999.99'],
            'is_active' => ['sometimes', 'required', 'boolean'],
        ];
    }

    /**
     * The validated attributes ready to persist, with any unit price in centavos.
     *
     * @return array<string, mixed>
     */
    public function priceListItemAttributes(): array
    {
        $attributes = $this->safe()->except('unit_price');

        if ($this->has('unit_price')) {
            $attributes['unit_price_centavos'] = PesoAmount::toCentavos($this->validated('unit_price'));
        }

        return $attributes;
    }
}
