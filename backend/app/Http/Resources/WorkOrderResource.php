<?php

namespace App\Http\Resources;

use App\Billing\BoqQuantity;
use App\Billing\PesoAmount;
use App\Billing\QuantityDiscrepancy;
use App\Models\WorkOrder;
use App\Models\WorkOrderAttachment;
use App\Models\WorkOrderLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A Work Order. `estimated_amount` / `billing_amount` are exact totals rounded once, half-up, to the centavo (two
 * decimals); the `_exact` amounts and line totals have five decimals (ADR 0002). Each line also marks the discrepancy
 * between the approved and the latest reported actual quantity (issue 21), and shows the EIC's validated quantity.
 * `has_discrepancies` is null until the vendor has declared completion. Storage paths stay private.
 *
 * @mixin WorkOrder
 */
class WorkOrderResource extends JsonResource
{
    /**
     * Relations to eager-load wherever this resource is returned.
     */
    public const array RELATIONS = ['lines', 'attachments', 'proposal', 'latestCompletion.lines'];

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $actualByLine = $this->latestCompletion?->lines->pluck('actual_quantity_thousandths', 'work_order_line_id');
        $lines = $this->lines->map(fn (WorkOrderLine $line): array => [
            'id' => $line->id,
            'e_call_proposal_line_id' => $line->e_call_proposal_line_id,
            'code' => $line->code,
            'description' => $line->description,
            'type' => $line->type->value,
            'unit_of_measure' => $line->unit_of_measure,
            'quantity' => BoqQuantity::fromThousandths($line->quantity_thousandths),
            'unit_price' => PesoAmount::fromCentavos($line->unit_price_centavos),
            'line_total' => PesoAmount::fromMillicentavos($line->line_total_millicentavos),
            ...QuantityDiscrepancy::describe($line->quantity_thousandths, $actualByLine?->get($line->id), $line->unit_price_centavos),
            'validated_quantity' => $line->validated_quantity_thousandths === null ? null : BoqQuantity::fromThousandths($line->validated_quantity_thousandths),
            'validated_line_total' => $line->validated_line_total_millicentavos === null ? null : PesoAmount::fromMillicentavos($line->validated_line_total_millicentavos),
        ]);

        return [
            'id' => $this->id,
            'e_call_id' => $this->e_call_id,
            'proposal' => ['id' => $this->e_call_proposal_id, 'revision' => $this->proposal->revision],
            'status' => $this->status->value,
            'created_by_user_id' => $this->created_by_user_id,
            'lines' => $lines->all(),
            'has_discrepancies' => $actualByLine === null ? null : $lines->contains(fn (array $line): bool => $line['discrepancy'] !== QuantityDiscrepancy::EQUAL),
            'estimated_amount' => PesoAmount::fromCentavos($this->estimated_amount_centavos),
            'estimated_amount_exact' => PesoAmount::fromMillicentavos($this->estimated_amount_millicentavos),
            'billing_amount' => $this->billing_amount_centavos === null ? null : PesoAmount::fromCentavos($this->billing_amount_centavos),
            'billing_amount_exact' => $this->billing_amount_millicentavos === null ? null : PesoAmount::fromMillicentavos($this->billing_amount_millicentavos),
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
