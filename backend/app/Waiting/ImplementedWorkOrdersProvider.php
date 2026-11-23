<?php

namespace App\Waiting;

use App\Enums\WorkOrderStatus;
use App\Models\SiteSetting;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Implemented Work Orders at every Site (through the E-Call) where the user is the CURRENT Site EIC, who validates
 * the completed work and sets the Billing Amount (issue 21). Same rule as WorkOrder::isActingEic, in bulk.
 */
class ImplementedWorkOrdersProvider implements WaitingItemProvider
{
    public function itemsFor(User $user): iterable
    {
        return WorkOrderWaitingItems::from(
            WorkOrderWaitingItems::query([WorkOrderStatus::Implemented])
                ->whereHas('eCall', fn (Builder $query) => $query->whereIn('site_id', SiteSetting::siteIdsWhereActingEic($user)))
                ->get(),
        );
    }
}
