<?php

namespace Tests\Unit\Dashboard;

use App\Dashboard\PmCompliance;
use App\Dashboard\PmTaskDates;
use App\Enums\PmStatus;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PmComplianceTest extends TestCase
{
    private const FROM = '2026-11-01';

    private const TO = '2026-11-30';

    private const TODAY = '2026-11-20';

    private static function task(PmStatus $status, string $dueDate, ?string $workDoneDate = null): PmTaskDates
    {
        return new PmTaskDates($status, new DateTimeImmutable($dueDate), $workDoneDate === null ? null : new DateTimeImmutable($workDoneDate));
    }

    /**
     * @param  list<PmTaskDates>  $tasks
     * @return array{due: int, on_time: int, percent: float|null}
     */
    private static function calculate(array $tasks): array
    {
        return PmCompliance::calculate($tasks, new DateTimeImmutable(self::FROM), new DateTimeImmutable(self::TO), new DateTimeImmutable(self::TODAY));
    }

    public function test_a_completed_task_done_on_its_due_date_is_compliant(): void
    {
        $this->assertSame(['due' => 1, 'on_time' => 1, 'percent' => 100.0], self::calculate([
            self::task(PmStatus::Completed, '2026-11-10', '2026-11-10'),
        ]));
    }

    public function test_a_completed_task_done_a_day_late_is_not_compliant(): void
    {
        $this->assertSame(['due' => 1, 'on_time' => 0, 'percent' => 0.0], self::calculate([
            self::task(PmStatus::Completed, '2026-11-10', '2026-11-11'),
        ]));
    }

    /**
     * @return array<string, array{PmStatus, string|null}>
     */
    public static function notDoneStatuses(): array
    {
        return [
            'for validation with an on-time work done date' => [PmStatus::ForValidation, '2026-11-09'],
            'returned' => [PmStatus::Returned, '2026-11-09'],
            'in progress' => [PmStatus::InProgress, null],
            'scheduled' => [PmStatus::Scheduled, null],
        ];
    }

    #[DataProvider('notDoneStatuses')]
    public function test_a_task_not_yet_completed_past_its_due_date_is_due_but_not_compliant(PmStatus $status, ?string $workDoneDate): void
    {
        $this->assertSame(['due' => 1, 'on_time' => 0, 'percent' => 0.0], self::calculate([
            self::task($status, '2026-11-10', $workDoneDate),
        ]));
    }

    public function test_a_cancelled_task_is_left_out(): void
    {
        $this->assertSame(['due' => 0, 'on_time' => 0, 'percent' => null], self::calculate([
            self::task(PmStatus::Cancelled, '2026-11-10'),
        ]));
    }

    public function test_a_task_due_after_today_is_left_out_even_inside_the_period(): void
    {
        $this->assertSame(['due' => 1, 'on_time' => 1, 'percent' => 100.0], self::calculate([
            self::task(PmStatus::Completed, '2026-11-20', '2026-11-19'),
            self::task(PmStatus::Completed, '2026-11-21', '2026-11-19'),
            self::task(PmStatus::Scheduled, '2026-11-25'),
        ]));
    }

    public function test_a_task_due_outside_the_period_is_left_out(): void
    {
        $this->assertSame(['due' => 1, 'on_time' => 0, 'percent' => 0.0], self::calculate([
            self::task(PmStatus::Completed, '2026-10-31', '2026-10-31'),
            self::task(PmStatus::Scheduled, '2026-11-01'),
        ]));
    }

    public function test_a_period_ending_before_today_counts_up_to_its_end(): void
    {
        $result = PmCompliance::calculate(
            [self::task(PmStatus::Completed, '2026-10-31', '2026-10-30'), self::task(PmStatus::Scheduled, '2026-11-01')],
            new DateTimeImmutable('2026-10-01'),
            new DateTimeImmutable('2026-10-31'),
            new DateTimeImmutable(self::TODAY),
        );

        $this->assertSame(['due' => 1, 'on_time' => 1, 'percent' => 100.0], $result);
    }

    public function test_no_due_tasks_gives_a_null_percent(): void
    {
        $this->assertSame(['due' => 0, 'on_time' => 0, 'percent' => null], self::calculate([]));
    }

    /**
     * @return array<string, array{int, int, float}>
     */
    public static function roundings(): array
    {
        return [
            'two thirds rounds up' => [2, 3, 66.7],
            'one third rounds down' => [1, 3, 33.3],
            'exact half rounds up (1/8 = 12.5)' => [1, 8, 12.5],
            'half of a tenth rounds up (1/16 = 6.25)' => [1, 16, 6.3],
            'half of a tenth rounds up (3/16 = 18.75)' => [3, 16, 18.8],
        ];
    }

    #[DataProvider('roundings')]
    public function test_percent_is_rounded_half_up_to_one_decimal(int $onTime, int $due, float $expected): void
    {
        $tasks = [];
        for ($i = 0; $i < $due; $i++) {
            $tasks[] = self::task(PmStatus::Completed, '2026-11-10', $i < $onTime ? '2026-11-10' : '2026-11-12');
        }

        $this->assertSame($expected, self::calculate($tasks)['percent']);
    }
}
