<?php

namespace App\Billing;

use InvalidArgumentException;

/**
 * The two Billing Threshold amounts, in centavos. Lower must be positive and strictly below upper.
 */
final readonly class BillingThresholds
{
    public function __construct(
        public int $lowerInCentavos,
        public int $upperInCentavos,
    ) {
        if ($lowerInCentavos <= 0 || $upperInCentavos <= $lowerInCentavos) {
            throw new InvalidArgumentException('Billing thresholds must be positive, with lower strictly below upper.');
        }
    }
}
