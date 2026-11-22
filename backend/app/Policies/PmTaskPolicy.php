<?php

namespace App\Policies;

use App\Models\PmTask;
use App\Models\User;

/**
 * Only staff of a PM Task's assigned Vendor work on it. Vendor staff only see their own Vendor's tasks;
 * other users are not scoped yet (issue 04).
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
}
