<?php

namespace App\Listeners;

use App\Enums\PmStatus;
use App\Events\SitePmFrequencyChanged;
use App\Models\PmTask;

/**
 * When a Site's PM Frequency changes, re-date its PM Tasks that are still Scheduled: new due date = the date the
 * task counts from (last Work Done Date, else installation date) + the new interval. In Progress, For Validation
 * and Returned tasks keep their due date.
 */
class RedateScheduledPmTasks
{
    public function handle(SitePmFrequencyChanged $event): void
    {
        PmTask::query()
            ->where('site_id', $event->siteId)
            ->where('status', PmStatus::Scheduled)
            ->chunkById(200, function ($tasks) use ($event): void {
                foreach ($tasks as $task) {
                    $task->update(['due_date' => $event->newFrequency->nextDueDate($task->due_from_date)]);
                }
            });
    }
}
