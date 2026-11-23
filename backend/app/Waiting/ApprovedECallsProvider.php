<?php

namespace App\Waiting;

use App\Enums\ECallStatus;
use App\Models\User;

/**
 * Approved E-Calls the user filed: the Requestor creates the Work Order (issue 18). One query.
 */
class ApprovedECallsProvider implements WaitingItemProvider
{
    public function itemsFor(User $user): iterable
    {
        return ECallWaitingItems::from(
            ECallWaitingItems::query([ECallStatus::Approved])->where('requestor_user_id', $user->id),
        );
    }
}
