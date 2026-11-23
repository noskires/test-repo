<?php

namespace App\Waiting;

use App\Enums\WorkOrderStatus;
use App\Models\User;

/**
 * Open Work Orders the user created: the Requestor edits and submits them for endorsement (issues 18-19).
 */
class OpenWorkOrdersProvider implements WaitingItemProvider
{
    public function itemsFor(User $user): iterable
    {
        return WorkOrderWaitingItems::from(
            WorkOrderWaitingItems::query([WorkOrderStatus::Open])->where('created_by_user_id', $user->id)->get(),
        );
    }
}
