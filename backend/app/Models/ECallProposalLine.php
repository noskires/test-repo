<?php

namespace App\Models;

use App\Enums\PriceListItemType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One BOQ line: a Site Vendor Price List item with a quantity. The item's details and unit price are copied
 * at submission, so later Price List changes don't touch it. The line total is exact, in millicentavos.
 */
#[Fillable([
    'price_list_item_id', 'code', 'description', 'type', 'unit_of_measure',
    'quantity_thousandths', 'unit_price_centavos', 'line_total_millicentavos',
])]
class ECallProposalLine extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'e_call_proposal_id' => 'integer',
            'price_list_item_id' => 'integer',
            'type' => PriceListItemType::class,
            'quantity_thousandths' => 'integer',
            'unit_price_centavos' => 'integer',
            'line_total_millicentavos' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<ECallProposal, $this>
     */
    public function proposal(): BelongsTo
    {
        return $this->belongsTo(ECallProposal::class, 'e_call_proposal_id');
    }

    /**
     * @return BelongsTo<PriceListItem, $this>
     */
    public function priceListItem(): BelongsTo
    {
        return $this->belongsTo(PriceListItem::class);
    }
}
