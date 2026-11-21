<?php

namespace App\Billing;

use InvalidArgumentException;

/**
 * Decides how many Billing Endorsement levels a Billing Amount needs (PRD §8.5).
 */
final class BillingLevels
{
    /**
     * Below the lower threshold: 2. From lower to upper, inclusive: 3. Above upper: 4.
     */
    public static function requiredBillingLevels(int $billingAmountInCentavos, BillingThresholds $thresholds): int
    {
        if ($billingAmountInCentavos < 0) {
            throw new InvalidArgumentException('A Billing Amount cannot be negative.');
        }

        return match (true) {
            $billingAmountInCentavos < $thresholds->lowerInCentavos => 2,
            $billingAmountInCentavos <= $thresholds->upperInCentavos => 3,
            default => 4,
        };
    }
}
