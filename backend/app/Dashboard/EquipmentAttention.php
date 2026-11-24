<?php

namespace App\Dashboard;

use App\Enums\PmTimeliness;
use App\Scheduling\PmTimelinessRule;
use DateTimeInterface;

/**
 * Equipment Requiring Attention (issue 28): which reasons apply to one Equipment, in AttentionReason order. An empty
 * list means it needs no attention. Pure: the caller gathers the facts and passes "today" as a Manila date.
 */
final class EquipmentAttention
{
    /**
     * @param  iterable<PmTaskDates>  $openPmTasks  The Equipment's open PM Tasks (normally at most one).
     * @param  bool  $latestCompletedReportHasFailedItem  The latest revision of the PM Report of its most recent Completed PM Task has a failed response.
     * @param  bool  $hasOpenEmergencyECall  It has an E-Call with Emergency priority that isn't Closed, Rejected or Cancelled.
     * @return list<AttentionReason>
     */
    public static function reasons(
        iterable $openPmTasks,
        bool $latestCompletedReportHasFailedItem,
        bool $hasOpenEmergencyECall,
        OperatingState $operating,
        DateTimeInterface $today,
    ): array {
        $applies = [
            AttentionReason::OverduePm->value => self::hasOverduePm($openPmTasks, $today),
            AttentionReason::FailedItems->value => $latestCompletedReportHasFailedItem,
            AttentionReason::EmergencyECall->value => $hasOpenEmergencyECall,
            AttentionReason::NotOperating->value => $operating === OperatingState::NonOperational,
        ];

        return array_values(array_filter(AttentionReason::cases(), fn (AttentionReason $reason): bool => $applies[$reason->value]));
    }

    /**
     * @param  iterable<PmTaskDates>  $openPmTasks
     */
    private static function hasOverduePm(iterable $openPmTasks, DateTimeInterface $today): bool
    {
        foreach ($openPmTasks as $task) {
            if (PmTimelinessRule::timeliness($task->dueDate, $today, $task->status, $task->workDoneDate) === PmTimeliness::Overdue) {
                return true;
            }
        }

        return false;
    }
}
