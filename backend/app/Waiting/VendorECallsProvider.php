<?php

namespace App\Waiting;

use App\Enums\ECallStatus;
use App\Models\User;

/**
 * For vendor staff: E-Calls routed to their Vendor (e_calls.vendor_id) that wait for an FSR and BOQ, i.e. For Vendor
 * Assessment or Returned (ECallStatus::acceptsProposal). One query.
 */
class VendorECallsProvider implements WaitingItemProvider
{
    public function itemsFor(User $user): iterable
    {
        if ($user->vendor_id === null) {
            return [];
        }

        $statuses = array_values(array_filter(ECallStatus::cases(), fn (ECallStatus $status): bool => $status->acceptsProposal()));

        return ECallWaitingItems::from(
            ECallWaitingItems::query($statuses)->where('vendor_id', $user->vendor_id),
        );
    }
}
