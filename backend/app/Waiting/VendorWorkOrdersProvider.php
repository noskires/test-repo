<?php

namespace App\Waiting;

use App\Enums\WorkOrderStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * For vendor staff: Work Orders For Implementation whose E-Call is routed to their Vendor (issue 20).
 */
class VendorWorkOrdersProvider implements WaitingItemProvider
{
    public function itemsFor(User $user): iterable
    {
        if ($user->vendor_id === null) {
            return [];
        }

        return WorkOrderWaitingItems::from(
            WorkOrderWaitingItems::query([WorkOrderStatus::ForImplementation])
                ->whereHas('eCall', fn (Builder $query) => $query->where('vendor_id', $user->vendor_id))
                ->get(),
        );
    }
}
