<?php

namespace App\Models;

use App\Enums\PriceListItemType;
use Database\Factories\PriceListItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One contract-agreed, priced item on a Vendor's Price List. Deactivated, never deleted.
 */
#[Fillable(['code', 'description', 'type', 'unit_of_measure', 'unit_price_centavos', 'is_active'])]
class PriceListItem extends Model
{
    /** @use HasFactory<PriceListItemFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_active' => true,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'vendor_id' => 'integer',
            'type' => PriceListItemType::class,
            'unit_price_centavos' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Vendor, $this>
     */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }
}
