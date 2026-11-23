<?php

namespace App\Scheduling;

use App\Enums\PmStatus;
use App\Enums\PmTimeliness;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Computes PM Timeliness (issue 09). Pure: no database and no clock, so the caller passes "today" as a Manila date.
 * Only calendar dates count; the time of day and time zone of each argument are ignored.
 */
final class PmTimelinessRule
{
    /** A task becomes Upcoming this many days before its due date, for every PM Frequency. */
    public const UPCOMING_DAYS = 7;

    public static function timeliness(DateTimeInterface $dueDate, DateTimeInterface $today, PmStatus $status, ?DateTimeInterface $workDoneDate): ?PmTimeliness
    {
        if (! $status->isOpen()) {
            return null;
        }

        // A submitted task is judged by when the work was done, not by when the EIC approves it.
        if ($status === PmStatus::ForValidation && $workDoneDate !== null) {
            return self::daysBetween($dueDate, $workDoneDate) > 0 ? PmTimeliness::Overdue : null;
        }

        // Scheduled, In Progress and Returned follow the date rule. Returned keeps its old Work Done Date, which is ignored.
        $daysPastDue = self::daysBetween($dueDate, $today);

        return match (true) {
            $daysPastDue > 0 => PmTimeliness::Overdue,
            $daysPastDue === 0 => PmTimeliness::Due,
            $daysPastDue >= -self::UPCOMING_DAYS => PmTimeliness::Upcoming,
            default => null,
        };
    }

    /**
     * Whole calendar days from $from to $to (negative when $to is earlier).
     */
    private static function daysBetween(DateTimeInterface $from, DateTimeInterface $to): int
    {
        $zone = $from->getTimezone();
        $interval = self::calendarDate($from, $zone)->diff(self::calendarDate($to, $zone));

        return $interval->invert === 1 ? -(int) $interval->days : (int) $interval->days;
    }

    /**
     * Midnight of the argument's own calendar date, placed in one shared zone so only the dates are compared.
     */
    private static function calendarDate(DateTimeInterface $date, DateTimeZone $zone): DateTimeImmutable
    {
        return new DateTimeImmutable($date->format('Y-m-d').' 00:00:00', $zone);
    }
}
