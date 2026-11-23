<?php

namespace App\Waiting;

use App\Enums\PmStatus;
use App\Models\ApprovalRecord;
use App\Models\PmTask;
use App\Models\User;

/**
 * For vendor staff: their Vendor's PM Tasks that are Scheduled, In Progress or Returned. Waiting since: Scheduled =
 * when the task was created, In Progress = started_at, Returned = the latest Approval Record. One query.
 */
class VendorPmTasksProvider implements WaitingItemProvider
{
    public function itemsFor(User $user): iterable
    {
        if ($user->vendor_id === null) {
            return [];
        }

        return PmTask::query()
            ->where('vendor_id', $user->vendor_id)
            ->whereIn('status', [PmStatus::Scheduled, PmStatus::InProgress, PmStatus::Returned])
            ->select('pm_tasks.*')
            ->addSelect(['latest_approval_at' => ApprovalRecord::query()
                ->select('created_at')
                ->where('subject_type', (new PmTask)->getMorphClass())
                ->whereColumn('subject_id', 'pm_tasks.id')
                ->orderByDesc('id')
                ->limit(1),
            ])
            ->withCasts(['latest_approval_at' => 'immutable_datetime'])
            ->get()
            ->map(fn (PmTask $task): WaitingItem => PmTaskWaitingItems::make($task, match ($task->status) {
                PmStatus::Returned => $task->latest_approval_at,
                PmStatus::InProgress => $task->started_at,
                default => $task->created_at?->toImmutable(),
            }));
    }
}
