<?php

namespace Tests\Unit\Dashboard;

use App\Dashboard\AttentionReason;
use App\Dashboard\EquipmentAttention;
use App\Dashboard\OperatingState;
use App\Dashboard\PmTaskDates;
use App\Enums\PmStatus;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

class EquipmentAttentionTest extends TestCase
{
    private const TODAY = '2026-11-20';

    /**
     * @param  list<PmTaskDates>  $openPmTasks
     * @return list<AttentionReason>
     */
    private static function reasons(
        array $openPmTasks = [],
        bool $failedItems = false,
        bool $emergencyECall = false,
        OperatingState $operating = OperatingState::Operational,
    ): array {
        return EquipmentAttention::reasons($openPmTasks, $failedItems, $emergencyECall, $operating, new DateTimeImmutable(self::TODAY));
    }

    private static function openTask(PmStatus $status, string $dueDate, ?string $workDoneDate = null): PmTaskDates
    {
        return new PmTaskDates($status, new DateTimeImmutable($dueDate), $workDoneDate === null ? null : new DateTimeImmutable($workDoneDate));
    }

    public function test_equipment_with_nothing_wrong_needs_no_attention(): void
    {
        $this->assertSame([], self::reasons());
    }

    public function test_an_overdue_open_pm_task(): void
    {
        $this->assertSame([AttentionReason::OverduePm], self::reasons([self::openTask(PmStatus::Scheduled, '2026-11-19')]));
    }

    public function test_an_open_pm_task_due_today_or_upcoming_is_not_overdue(): void
    {
        $this->assertSame([], self::reasons([self::openTask(PmStatus::InProgress, '2026-11-20')]));
        $this->assertSame([], self::reasons([self::openTask(PmStatus::Scheduled, '2026-11-25')]));
    }

    public function test_a_task_submitted_late_is_overdue_and_one_submitted_on_time_is_not(): void
    {
        $this->assertSame([AttentionReason::OverduePm], self::reasons([self::openTask(PmStatus::ForValidation, '2026-11-10', '2026-11-11')]));
        $this->assertSame([], self::reasons([self::openTask(PmStatus::ForValidation, '2026-11-10', '2026-11-10')]));
    }

    public function test_failed_items_on_the_latest_report(): void
    {
        $this->assertSame([AttentionReason::FailedItems], self::reasons(failedItems: true));
    }

    public function test_an_open_emergency_e_call(): void
    {
        $this->assertSame([AttentionReason::EmergencyECall], self::reasons(emergencyECall: true));
    }

    public function test_not_operating(): void
    {
        $this->assertSame([AttentionReason::NotOperating], self::reasons(operating: OperatingState::NonOperational));
    }

    public function test_an_unknown_operating_status_does_not_count(): void
    {
        $this->assertSame([], self::reasons(operating: OperatingState::Unknown));
    }

    public function test_several_reasons_come_back_in_the_fixed_order(): void
    {
        $this->assertSame(
            [AttentionReason::OverduePm, AttentionReason::FailedItems, AttentionReason::EmergencyECall, AttentionReason::NotOperating],
            self::reasons([self::openTask(PmStatus::Returned, '2026-11-01')], true, true, OperatingState::NonOperational),
        );
    }
}
