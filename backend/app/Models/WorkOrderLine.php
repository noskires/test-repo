<?php

namespace App\Models;

use App\Enums\PriceListItemType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One Work Order line, picked from a line of the approved BOQ revision with an approved quantity (at most the BOQ
 * quantity). Description, unit and unit price are copied from the BOQ line. The line total is exact (millicentavos).
 * The EIC's validated quantity and its exact total (issue 21) are null until the completion is validated.
 */
#[Fillable([
    'e_call_proposal_line_id', 'code', 'description', 'type', 'unit_of_measure',
    'quantity_thousandths', 'unit_price_centavos', 'line_total_millicentavos',
    'validated_quantity_thousandths', 'validated_line_total_millicentavos',
])]
class WorkOrderLine extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'work_order_id' => 'integer',
            'e_call_proposal_line_id' => 'integer',
            'type' => PriceListItemType::class,
            'quantity_thousandths' => 'integer',
            'unit_price_centavos' => 'integer',
            'line_total_millicentavos' => 'integer',
            'validated_quantity_thousandths' => 'integer',
            'validated_line_total_millicentavos' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<ECallProposalLine, $this>
     */
    public function boqLine(): BelongsTo
    {
        return $this->belongsTo(ECallProposalLine::class, 'e_call_proposal_line_id');
    }
}
