<?php

namespace App\Models;

use App\Billing\BillingThresholds;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * The single row holding the current Billing Thresholds (seeded at ₱200,000 and ₱1,000,000 by its migration).
 */
#[Fillable(['lower_threshold_centavos', 'upper_threshold_centavos'])]
class BillingThresholdSetting extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'lower_threshold_centavos' => 'integer',
            'upper_threshold_centavos' => 'integer',
        ];
    }

    /**
     * The Billing Thresholds in force right now.
     */
    public static function current(): BillingThresholds
    {
        return static::query()->sole()->toBillingThresholds();
    }

    /**
     * Replace the current thresholds and record who changed them, when, and the old and new values.
     * Saving identical values records nothing.
     */
    public static function changeTo(BillingThresholds $newThresholds, User $changedBy): BillingThresholds
    {
        return DB::transaction(function () use ($newThresholds, $changedBy): BillingThresholds {
            $setting = static::query()->lockForUpdate()->sole();
            $oldThresholds = $setting->toBillingThresholds();

            if ($oldThresholds == $newThresholds) {
                return $oldThresholds;
            }

            $setting->update([
                'lower_threshold_centavos' => $newThresholds->lowerInCentavos,
                'upper_threshold_centavos' => $newThresholds->upperInCentavos,
            ]);

            BillingThresholdChange::create([
                'changed_by_user_id' => $changedBy->id,
                'old_lower_threshold_centavos' => $oldThresholds->lowerInCentavos,
                'old_upper_threshold_centavos' => $oldThresholds->upperInCentavos,
                'new_lower_threshold_centavos' => $newThresholds->lowerInCentavos,
                'new_upper_threshold_centavos' => $newThresholds->upperInCentavos,
            ]);

            return $newThresholds;
        });
    }

    public function toBillingThresholds(): BillingThresholds
    {
        return new BillingThresholds($this->lower_threshold_centavos, $this->upper_threshold_centavos);
    }
}
