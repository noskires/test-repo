<?php

namespace App\Waiting;

use App\Enums\WorkOrderStatus;
use App\MasterData\ImmediateHeadChain;
use App\Models\User;
use App\Models\WorkOrder;

/**
 * Work Orders waiting at an endorsement level (LVL1 / LVL2) or a billing endorsement level (LVL1-4) whose CURRENT
 * holder in the Requestor's Immediate Head chain is the user (issues 19 and 23), decided by
 * WorkOrder::isWaitingForEndorsementBy. A level that doesn't resolve or a conflicting chain shows the Work Order to
 * nobody here (it belongs on the Needs Setup list, issue 32). One ImmediateHeadChain is shared across the list, so
 * each employee is looked up in the directory once per request.
 */
class WorkOrderEndorsementsProvider implements WaitingItemProvider
{
    public function itemsFor(User $user): iterable
    {
        if (! $user->isInternalEmployee()) {
            return [];
        }

        $statuses = array_values(array_filter(
            WorkOrderStatus::cases(),
            fn (WorkOrderStatus $status): bool => $status->endorsementLevel() !== null || $status->billingLevel() !== null,
        ));
        $chain = app(ImmediateHeadChain::class);

        return WorkOrderWaitingItems::from(
            WorkOrderWaitingItems::query($statuses)
                ->get()
                ->filter(fn (WorkOrder $workOrder): bool => $workOrder->isWaitingForEndorsementBy($user, $chain)),
        );
    }
}
