<?php

namespace App\Http\Resources;

use App\Billing\BoqQuantity;
use App\Billing\PesoAmount;
use App\Models\WorkOrder;
use App\Models\WorkOrderAttachment;
use App\Models\WorkOrderLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A Work Order. `estimated_amount` is the exact total rounded half-up to the centavo (two decimals);
 * `estimated_amount_exact` and each line total are exact, with five decimals (ADR 0002). Storage paths stay private.
 *
 * @mixin WorkOrder
 */
class WorkOrderResource extends JsonResource
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
            'e_call_id' => $this->e_call_id,
            'proposal' => ['id' => $this->e_call_proposal_id, 'revision' => $this->proposal->revision],
            'status' => $this->status->value,
            'created_by_user_id' => $this->created_by_user_id,
            'lines' => $this->lines->map(fn (WorkOrderLine $line): array => [
                'id' => $line->id,
                'e_call_proposal_line_id' => $line->e_call_proposal_line_id,
                'code' => $line->code,
                'description' => $line->description,
                'type' => $line->type->value,
                'unit_of_measure' => $line->unit_of_measure,
                'quantity' => BoqQuantity::fromThousandths($line->quantity_thousandths),
                'unit_price' => PesoAmount::fromCentavos($line->unit_price_centavos),
                'line_total' => PesoAmount::fromMillicentavos($line->line_total_millicentavos),
            ])->all(),
            'estimated_amount' => PesoAmount::fromCentavos($this->estimated_amount_centavos),
            'estimated_amount_exact' => PesoAmount::fromMillicentavos($this->estimated_amount_millicentavos),
            'attachments' => $this->attachments->map(fn (WorkOrderAttachment $attachment): array => [
                'id' => $attachment->id,
                'original_name' => $attachment->original_name,
                'mime_type' => $attachment->mime_type,
                'size_bytes' => $attachment->size_bytes,
            ])->all(),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
