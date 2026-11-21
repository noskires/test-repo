<?php

namespace App\Http\Resources;

use App\Billing\BillingThresholds;
use App\Billing\PesoAmount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin BillingThresholds
 */
class BillingThresholdsResource extends JsonResource
{
    /**
     * Transform the resource into an array. Amounts are two-decimal peso strings.
     *
     * @return array{lower_threshold: string, upper_threshold: string}
     */
    public function toArray(Request $request): array
    {
        return [
            'lower_threshold' => PesoAmount::fromCentavos($this->lowerInCentavos),
            'upper_threshold' => PesoAmount::fromCentavos($this->upperInCentavos),
        ];
    }
}
