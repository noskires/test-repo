<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Support\Facades\Gate;

/**
 * A Work Order is visible like its E-Call (until issue 04 picks one visibility rule), and only its creator edits it.
 * EIC actions on Work Orders (issues 21, 25) must use SiteSetting::isActingEic.
 */
class WorkOrderPolicy
{
    /**
     * Determine whether the user can view the Work Order: the same rule as its E-Call (ECallPolicy::view).
     */
    public function view(User $user, WorkOrder $workOrder): bool
    {
        return Gate::forUser($user)->allows('view', $workOrder->eCall);
    }

    /**
     * Determine whether the user can edit the Work Order: only its creator. WorkOrder::revise repeats this under a
     * lock and also requires the Open status.
     */
    public function update(User $user, WorkOrder $workOrder): bool
    {
        return $user->id === $workOrder->created_by_user_id;
    }
}
