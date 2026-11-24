<?php

namespace App\Policies;

use App\Models\PmTask;
use App\Models\SiteSetting;
use App\Models\User;

/**
 * Only staff of a PM Task's assigned Vendor work on it, and only the Site's current EIC validates its report. Vendor staff only see their own Vendor's tasks; every internal employee sees every Site's tasks (issue 04, Q5).
 */
class PmTaskPolicy
{
    /**
     * Determine whether the user can see the task, its reports and its Approval Records.
     */
    public function view(User $user, PmTask $pmTask): bool
    {
        return $user->vendor_id === null || $user->vendor_id === $pmTask->vendor_id;
    }

    /**
     * Determine whether the user can start the task and submit its PM Report.
     */
    public function work(User $user, PmTask $pmTask): bool
    {
        return $user->vendor_id !== null && $user->vendor_id === $pmTask->vendor_id;
    }

    /**
     * Determine whether the user may act as the Site's EIC (SiteSetting::isActingEic: the CURRENT EIC, never vendor
     * staff), who approves or returns the PM Report. The EIC captured on the task is history only.
     * PmTask::validateReport repeats this check under a lock.
     */
    public function validate(User $user, PmTask $pmTask): bool
    {
        return SiteSetting::isActingEic($pmTask->site_id, $user);
    }
}
