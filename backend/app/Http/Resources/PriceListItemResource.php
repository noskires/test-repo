<?php

namespace App\Http\Resources;

use App\Billing\PesoAmount;
use App\Models\PriceListItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PriceListItem
 */
class PriceListItemResource extends JsonResource
{
    /**
     * Transform the resource into an array. The unit price is a two-decimal peso string.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'vendor_id' => $this->vendor_id,
            'code' => $this->code,
            'description' => $this->description,
            'type' => $this->type->value,
            'unit_of_measure' => $this->unit_of_measure,
            'unit_price' => PesoAmount::fromCentavos($this->unit_price_centavos),
            'is_active' => $this->is_active,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
