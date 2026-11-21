<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateBillingThresholdsRequest;
use App\Http\Resources\BillingThresholdsResource;
use App\Models\BillingThresholdSetting;

/**
 * Read and change the Billing Thresholds. Changing them needs the manage-billing-thresholds Gate.
 */
class BillingThresholdController extends Controller
{
    /**
     * Show the current thresholds.
     */
    public function show(): BillingThresholdsResource
    {
        return BillingThresholdsResource::make(BillingThresholdSetting::current());
    }

    /**
     * Replace both thresholds and record the change.
     */
    public function update(UpdateBillingThresholdsRequest $request): BillingThresholdsResource
    {
        return BillingThresholdsResource::make(
            BillingThresholdSetting::changeTo($request->billingThresholds(), $request->user()),
        );
    }
}
