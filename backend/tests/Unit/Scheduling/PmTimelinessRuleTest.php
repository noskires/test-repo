<?php

namespace Tests\Unit\Scheduling;

use App\Enums\PmStatus;
use App\Enums\PmTimeliness;
use App\Scheduling\PmTimelinessRule;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PmTimelinessRuleTest extends TestCase
{
    private const DUE = '2026-12-15';

    private static function at(string $date): DateTimeImmutable
    {
        return new DateTimeImmutable($date);
    }

    /**
     * @return array<string, array{string, ?PmTimeliness}>
     */
    public static function boundaries(): array
    {
        return [
            'due-30 is before the window' => ['2026-11-15', null],
            'due-8 is before the window' => ['2026-12-07', null],
            'due-7 is upcoming' => ['2026-12-08', PmTimeliness::Upcoming],
            'due-1 is upcoming' => ['2026-12-14', PmTimeliness::Upcoming],
            'due date is due' => ['2026-12-15', PmTimeliness::Due],
            'due+1 is overdue' => ['2026-12-16', PmTimeliness::Overdue],
            'due+60 is overdue' => ['2027-02-13', PmTimeliness::Overdue],
        ];
    }

    #[DataProvider('boundaries')]
    public function test_the_date_rule_at_every_boundary(string $today, ?PmTimeliness $expected): void
    {
        foreach ([PmStatus::Scheduled, PmStatus::InProgress, PmStatus::Returned] as $status) {
            $this->assertSame($expected, PmTimelinessRule::timeliness(self::at(self::DUE), self::at($today), $status, null), $status->value);
        }
    }

    public function test_the_window_crosses_a_month_and_year_end(): void
    {
        $due = self::at('2027-01-03');

        $this->assertNull(PmTimelinessRule::timeliness($due, self::at('2026-12-26'), PmStatus::Scheduled, null));
        $this->assertSame(PmTimeliness::Upcoming, PmTimelinessRule::timeliness($due, self::at('2026-12-27'), PmStatus::Scheduled, null));
        $this->assertSame(PmTimeliness::Overdue, PmTimelinessRule::timeliness($due, self::at('2027-01-04'), PmStatus::Scheduled, null));
    }

    public function test_the_time_of_day_is_ignored(): void
    {
        $this->assertSame(
            PmTimeliness::Due,
            PmTimelinessRule::timeliness(self::at(self::DUE.' 00:00:00'), self::at(self::DUE.' 23:59:59'), PmStatus::Scheduled, null),
        );
        $this->assertSame(
            PmTimeliness::Upcoming,
            PmTimelinessRule::timeliness(self::at(self::DUE.' 12:00:00'), self::at('2026-12-14 23:59:59'), PmStatus::Scheduled, null),
        );
    }

    /**
     * @return array<string, array{PmStatus}>
     */
    public static function closedStatuses(): array
    {
        return ['completed' => [PmStatus::Completed], 'cancelled' => [PmStatus::Cancelled]];
    }

    #[DataProvider('closedStatuses')]
    public function test_closed_tasks_have_no_timeliness_even_long_past_due(PmStatus $status): void
    {
        foreach (['2026-12-10', self::DUE, '2027-06-01'] as $today) {
            $this->assertNull(PmTimelinessRule::timeliness(self::at(self::DUE), self::at($today), $status, self::at('2027-01-20')));
            $this->assertNull(PmTimelinessRule::timeliness(self::at(self::DUE), self::at($today), $status, null));
        }
    }

    public function test_a_returned_task_past_due_is_overdue_even_with_an_on_time_work_done_date(): void
    {
        $this->assertSame(
            PmTimeliness::Overdue,
            PmTimelinessRule::timeliness(self::at(self::DUE), self::at('2026-12-20'), PmStatus::Returned, self::at('2026-12-10')),
        );
    }

    public function test_a_returned_task_ignores_a_late_work_done_date_before_the_due_date(): void
    {
        $this->assertSame(
            PmTimeliness::Upcoming,
            PmTimelinessRule::timeliness(self::at(self::DUE), self::at('2026-12-10'), PmStatus::Returned, self::at('2026-12-09')),
        );
    }

    /**
     * @return array<string, array{string, string, ?PmTimeliness}>
     */
    public static function forValidation(): array
    {
        return [
            'done before due, today after due' => ['2026-12-10', '2026-12-20', null],
            'done on due, today after due' => [self::DUE, '2027-01-30', null],
            'done on due, today on due' => [self::DUE, self::DUE, null],
            'done early, today in the upcoming window' => ['2026-12-09', '2026-12-10', null],
            'done one day late' => ['2026-12-16', '2026-12-16', PmTimeliness::Overdue],
            'done late, judged long after' => ['2026-12-20', '2027-01-30', PmTimeliness::Overdue],
        ];
    }

    #[DataProvider('forValidation')]
    public function test_a_for_validation_task_is_judged_by_its_work_done_date(string $workDone, string $today, ?PmTimeliness $expected): void
    {
        $this->assertSame(
            $expected,
            PmTimelinessRule::timeliness(self::at(self::DUE), self::at($today), PmStatus::ForValidation, self::at($workDone)),
        );
    }

    public function test_a_for_validation_task_without_a_work_done_date_falls_back_to_the_date_rule(): void
    {
        $this->assertSame(PmTimeliness::Overdue, PmTimelinessRule::timeliness(self::at(self::DUE), self::at('2026-12-16'), PmStatus::ForValidation, null));
        $this->assertSame(PmTimeliness::Due, PmTimelinessRule::timeliness(self::at(self::DUE), self::at(self::DUE), PmStatus::ForValidation, null));
    }

    public function test_the_enum_values_are_the_api_strings(): void
    {
        $this->assertSame(['upcoming', 'due', 'overdue'], array_map(fn (PmTimeliness $t): string => $t->value, PmTimeliness::cases()));
    }
}
