<?php

namespace App\Waiting;

use App\Models\PmTask;
use App\Scheduling\PmTimelinessRule;
use Carbon\CarbonImmutable;

/**
 * Turns a PM Task into a "Waiting for me" item: titled by its Equipment Snapshot, with its Site Snapshot,
 * PM Status and PM Timeliness (computed against today in the app timezone).
 */
final class PmTaskWaitingItems
{
    public const TYPE = 'pm_task';

    public static function make(PmTask $task, ?CarbonImmutable $waitingSince): WaitingItem
    {
        return new WaitingItem(
            type: self::TYPE,
            id: $task->id,
            title: $task->equipment_name_snapshot,
            siteId: $task->site_id,
            siteName: $task->site_name_snapshot,
            status: $task->status->value,
            timeliness: PmTimelinessRule::timeliness($task->due_date, today(), $task->status, $task->work_done_date)?->value,
            // Fallback for rows without the expected report, Approval Record or start time (not reachable through the API).
            waitingSince: $waitingSince ?? $task->updated_at->toImmutable(),
        );
    }
}
