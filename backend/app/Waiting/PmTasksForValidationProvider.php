<?php

namespace App\Waiting;

use App\Enums\PmStatus;
use App\Models\PmReport;
use App\Models\PmTask;
use App\Models\SiteSetting;
use App\Models\User;

/**
 * PM Tasks For Validation at every Site where the user is the current Site EIC. Waiting since the latest PM Report
 * revision was submitted. One query.
 */
class PmTasksForValidationProvider implements WaitingItemProvider
{
    public function itemsFor(User $user): iterable
    {
        return PmTask::query()
            ->where('status', PmStatus::ForValidation)
            ->whereIn('site_id', SiteSetting::siteIdsWhereActingEic($user))
            ->select('pm_tasks.*')
            ->addSelect(['latest_report_submitted_at' => PmReport::query()
                ->select('submitted_at')
                ->whereColumn('pm_task_id', 'pm_tasks.id')
                ->orderByDesc('revision')
                ->limit(1),
            ])
            ->withCasts(['latest_report_submitted_at' => 'immutable_datetime'])
            ->get()
            ->map(fn (PmTask $task): WaitingItem => PmTaskWaitingItems::make($task, $task->latest_report_submitted_at));
    }
}
