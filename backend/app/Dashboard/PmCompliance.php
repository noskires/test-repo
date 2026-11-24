<?php

namespace App\Dashboard;

use App\Enums\PmStatus;
use DateTimeInterface;

/**
 * PM Compliance (issue 28): PM Tasks done on time ÷ PM Tasks due in the period. Pure: no database and no clock, so
 * the caller passes the period and "today" as Manila dates. Only calendar dates count.
 *
 * Provisional rules (product-owner, 2026-11-24, to confirm with the human), each in one place:
 * - only Completed (EIC-approved) tasks count as done: countsAsDone();
 * - tasks due after today are left out of "due": dueWindowEnd();
 * - Cancelled tasks are left out: EXCLUDED_STATUSES.
 */
final class PmCompliance
{
    /** @var list<PmStatus> */
    public const EXCLUDED_STATUSES = [PmStatus::Cancelled];

    /**
     * @param  iterable<PmTaskDates>  $tasks
     * @return array{due: int, on_time: int, percent: float|null}
     */
    public static function calculate(iterable $tasks, DateTimeInterface $from, DateTimeInterface $to, DateTimeInterface $today): array
    {
        $windowStart = $from->format('Y-m-d');
        $windowEnd = self::dueWindowEnd($to, $today);
        $due = 0;
        $onTime = 0;

        foreach ($tasks as $task) {
            $dueDate = $task->dueDate->format('Y-m-d');
            if (in_array($task->status, self::EXCLUDED_STATUSES, true) || $dueDate < $windowStart || $dueDate > $windowEnd) {
                continue;
            }
            $due++;
            if (self::countsAsDone($task->status) && $task->workDoneDate !== null && $task->workDoneDate->format('Y-m-d') <= $dueDate) {
                $onTime++;
            }
        }

        return ['due' => $due, 'on_time' => $onTime, 'percent' => self::percent($onTime, $due)];
    }

    /**
     * Provisional: only an EIC-approved (Completed) task is done. A For Validation task with an on-time Work Done
     * Date isn't counted yet.
     */
    public static function countsAsDone(PmStatus $status): bool
    {
        return $status === PmStatus::Completed;
    }

    /**
     * The last due date that counts, as Y-m-d: the period end, or today when that is earlier. Provisional: tasks due
     * later in the period don't drag the figure down mid-month.
     */
    public static function dueWindowEnd(DateTimeInterface $to, DateTimeInterface $today): string
    {
        return min($to->format('Y-m-d'), $today->format('Y-m-d'));
    }

    /**
     * on_time ÷ due × 100, rounded half-up to one decimal place with integer arithmetic; null when nothing is due.
     */
    private static function percent(int $onTime, int $due): ?float
    {
        if ($due === 0) {
            return null;
        }

        $tenths = intdiv(2 * 1000 * $onTime + $due, 2 * $due);

        return $tenths / 10;
    }
}
