<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Audit entry for one change to the Billing Thresholds: who, when (created_at), and old and new values in centavos.
 */
#[Fillable([
    'changed_by_user_id',
    'old_lower_threshold_centavos',
    'old_upper_threshold_centavos',
    'new_lower_threshold_centavos',
    'new_upper_threshold_centavos',
])]
class BillingThresholdChange extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'changed_by_user_id' => 'integer',
            'old_lower_threshold_centavos' => 'integer',
            'old_upper_threshold_centavos' => 'integer',
            'new_lower_threshold_centavos' => 'integer',
            'new_upper_threshold_centavos' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_user_id');
    }
}
