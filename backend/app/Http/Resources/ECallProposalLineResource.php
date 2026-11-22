<?php

namespace App\Http\Resources;

use App\Billing\BoqQuantity;
use App\Billing\PesoAmount;
use App\Models\ECallProposalLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One BOQ line as submitted. The unit price has two decimals and the line total is exact with five decimals.
 *
 * @mixin ECallProposalLine
 */
class ECallProposalLineResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'price_list_item_id' => $this->price_list_item_id,
            'code' => $this->code,
            'description' => $this->description,
            'type' => $this->type->value,
            'unit_of_measure' => $this->unit_of_measure,
            'quantity' => BoqQuantity::fromThousandths($this->quantity_thousandths),
            'unit_price' => PesoAmount::fromCentavos($this->unit_price_centavos),
            'line_total' => PesoAmount::fromMillicentavos($this->line_total_millicentavos),
        ];
    }
}
