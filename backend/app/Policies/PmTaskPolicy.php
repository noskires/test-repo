<?php

namespace App\Policies;

use App\Models\PmTask;
use App\Models\SiteSetting;
use App\Models\User;

/**
 * Only staff of a PM Task's assigned Vendor work on it, and only the Site's current EIC validates its report. Vendor staff only see their own Vendor's tasks; other users are not scoped yet (issue 04).
 */
class PmTaskPolicy
{
    /**
     * Determine whether the user can see the task and its reports.
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
     * Determine whether the user is the Site's CURRENT EIC (site settings, read at action time), who approves or
     * returns the PM Report. The EIC captured on the task is history only: a replaced EIC can no longer validate.
     */
    public function validate(User $user, PmTask $pmTask): bool
    {
        return SiteSetting::isCurrentEic($pmTask->site_id, $user);
    }

    /**
     * Determine whether the user can read the task's Approval Records: the Site's current EIC and the task's
     * Vendor's staff.
     */
    public function viewApprovals(User $user, PmTask $pmTask): bool
    {
        return $this->validate($user, $pmTask) || $this->work($user, $pmTask);
    }
}
