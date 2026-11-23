<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderCompletion;
use Illuminate\Support\Facades\Gate;

/**
 * A Work Order is visible like its E-Call (until issue 04 picks one visibility rule). Only its creator edits and
 * submits it; only the Requestor's current Immediate Head for the pending level endorses it. EIC actions on Work
 * Orders (issues 21, 25) must use SiteSetting::isActingEic. WorkOrder repeats every check under a lock.
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
     * Determine whether the user can edit the Work Order: only its creator (and, under the lock, only while Open).
     */
    public function update(User $user, WorkOrder $workOrder): bool
    {
        return $user->id === $workOrder->created_by_user_id;
    }

    /**
     * Determine whether the user can submit the Work Order for endorsement: only its creator.
     */
    public function submit(User $user, WorkOrder $workOrder): bool
    {
        return $this->update($user, $workOrder);
    }

    /**
     * Determine whether the user can declare the Work Order complete: staff of its Site Vendor (the vendor captured
     * on its E-Call). Callers authorize `view` first. WorkOrderCompletion::submit repeats this under a lock.
     */
    public function declareCompletion(User $user, WorkOrder $workOrder): bool
    {
        return WorkOrderCompletion::isSiteVendorStaff($user, $workOrder);
    }

    /**
     * Early 403 for approve / return / reject: denied when the current level's endorser is known and isn't this
     * user. No pending level, or an endorser that can't be resolved, is reported as 422 by WorkOrder::endorse.
     * Every endorsement endpoint authorizes `view` first (issue 38), so a user who can't see the Work Order always
     * gets a plain 403 and learns nothing about its status or chain.
     */
    public function endorse(User $user, WorkOrder $workOrder): bool
    {
        return $workOrder->mayBeEndorsedBy($user);
    }
}
