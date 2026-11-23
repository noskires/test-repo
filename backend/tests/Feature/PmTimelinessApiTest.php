<?php

namespace Tests\Feature;

use App\Enums\PmStatus;
use App\Models\PmTask;
use App\Models\User;
use App\Models\Vendor;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PM Timeliness is computed on read and returned next to PM Status (issue 09).
 */
class PmTimelinessApiTest extends TestCase
{
    use RefreshDatabase;

    private Vendor $vendor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->vendor = Vendor::factory()->create();
        $this->actingAs(User::factory()->vendorStaff($this->vendor)->create());
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function task(PmStatus $status, string $dueDate, array $attributes = []): PmTask
    {
        return PmTask::factory()->create([
            'vendor_id' => $this->vendor->id,
            'status' => $status,
            'due_date' => $dueDate,
            'no_checklist' => true,
            ...$attributes,
        ]);
    }

    /**
     * @return array<int, mixed>
     */
    private function listedTimeliness(): array
    {
        return collect($this->getJson('/api/vendor/pm-tasks')->assertOk()->json('data'))
            ->mapWithKeys(fn (array $task): array => [$task['id'] => $task['timeliness']])
            ->sortKeys()
            ->all();
    }

    public function test_the_vendor_task_list_returns_timeliness_next_to_status(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-12-15 10:00:00', 'Asia/Manila'));

        $later = $this->task(PmStatus::Scheduled, '2026-12-23');
        $upcoming = $this->task(PmStatus::Scheduled, '2026-12-22');
        $due = $this->task(PmStatus::InProgress, '2026-12-15');
        $overdue = $this->task(PmStatus::Scheduled, '2026-12-14');
        $returnedOverdue = $this->task(PmStatus::Returned, '2026-12-10', ['work_done_date' => '2026-12-09']);
        $submittedOnTime = $this->task(PmStatus::ForValidation, '2026-12-10', ['work_done_date' => '2026-12-10']);
        $submittedLate = $this->task(PmStatus::ForValidation, '2026-12-10', ['work_done_date' => '2026-12-11']);
        $completed = $this->task(PmStatus::Completed, '2026-11-01', ['work_done_date' => '2026-11-20']);
        $cancelled = $this->task(PmStatus::Cancelled, '2026-11-01');

        $this->assertSame([
            $later->id => null,
            $upcoming->id => 'upcoming',
            $due->id => 'due',
            $overdue->id => 'overdue',
            $returnedOverdue->id => 'overdue',
            $submittedOnTime->id => null,
            $submittedLate->id => 'overdue',
            $completed->id => null,
            $cancelled->id => null,
        ], $this->listedTimeliness());

        $this->getJson('/api/vendor/pm-tasks')
            ->assertJsonPath('data.0.id', $completed->id)
            ->assertJsonPath('data.0.status', 'completed')
            ->assertJsonPath('data.0.timeliness', null);
    }

    public function test_starting_a_task_returns_its_timeliness(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-12-15 10:00:00', 'Asia/Manila'));
        $task = $this->task(PmStatus::Scheduled, '2026-12-15');

        $this->postJson("/api/pm-tasks/{$task->id}/start")
            ->assertOk()
            ->assertJsonPath('data.status', 'in_progress')
            ->assertJsonPath('data.timeliness', 'due');
    }

    public function test_today_is_the_manila_date_in_the_early_manila_hours(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-12-16 01:00:00', 'Asia/Manila'));
        $this->assertSame('2026-12-15', now()->utc()->toDateString(), 'Sanity check: UTC is still on the previous day.');

        $dueYesterday = $this->task(PmStatus::Scheduled, '2026-12-15');
        $dueToday = $this->task(PmStatus::Scheduled, '2026-12-16');
        $windowOpensToday = $this->task(PmStatus::Scheduled, '2026-12-23');

        $this->assertSame([
            $dueYesterday->id => 'overdue',
            $dueToday->id => 'due',
            $windowOpensToday->id => 'upcoming',
        ], $this->listedTimeliness());
    }

    public function test_today_is_the_manila_date_just_before_manila_midnight_utc_boundary(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-12-15 16:00:00', 'UTC'));
        $this->assertSame('2026-12-16', now()->toDateString(), 'Sanity check: 16:00 UTC is 00:00 Manila the next day.');

        $dueToday = $this->task(PmStatus::Scheduled, '2026-12-16');

        $this->postJson("/api/pm-tasks/{$dueToday->id}/start")
            ->assertOk()
            ->assertJsonPath('data.timeliness', 'due');
    }
}
