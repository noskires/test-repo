<?php

namespace Tests\Feature;

use App\Enums\PmStatus;
use App\Models\PmTask;
use App\Models\SiteSetting;
use App\Models\User;
use App\Models\Vendor;
use App\Waiting\WaitingItem;
use App\Waiting\WaitingItemProvider;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WaitingListApiTest extends TestCase
{
    use RefreshDatabase;

    private Vendor $vendor;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-22 10:00:00');
        $this->vendor = Vendor::factory()->create();
        SiteSetting::factory()->create(['site_id' => 'S-1', 'vendor_id' => $this->vendor->id, 'eic_employee_id' => 'P-EIC']);
        SiteSetting::factory()->create(['site_id' => 'S-2', 'vendor_id' => $this->vendor->id, 'eic_employee_id' => 'P-OTHER']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function task(array $attributes = []): PmTask
    {
        return PmTask::factory()->create([
            'site_id' => 'S-1',
            'site_name_snapshot' => 'North Plant',
            'vendor_id' => $this->vendor->id,
            'eic_employee_id' => 'P-EIC',
            'due_date' => '2026-11-25',
            'due_from_date' => '2026-11-01',
            ...$attributes,
        ]);
    }

    private function submitReport(PmTask $task, string $submittedAt, int $revision = 1): void
    {
        $task->reports()->create([
            'revision' => $revision,
            'work_done_date' => '2026-11-20',
            'submitted_by_user_id' => User::factory()->vendorStaff($this->vendor)->create()->id,
            'submitted_at' => $submittedAt,
        ]);
    }

    private function forValidationTask(string $submittedAt, array $attributes = []): PmTask
    {
        $task = $this->task(['status' => PmStatus::ForValidation, 'work_done_date' => '2026-11-20', ...$attributes]);
        $this->submitReport($task, $submittedAt);

        return $task;
    }

    private function eic(string $employeeId = 'P-EIC'): User
    {
        return User::factory()->create(['employee_id' => $employeeId]);
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->getJson('/api/me/waiting')->assertUnauthorized();
    }

    public function test_a_user_with_no_role_gets_an_empty_list(): void
    {
        $this->forValidationTask('2026-11-21 09:00:00');

        $this->actingAs(User::factory()->create(['employee_id' => 'P-NOBODY']))
            ->getJson('/api/me/waiting')
            ->assertOk()
            ->assertExactJson(['data' => []]);
    }

    public function test_an_eic_sees_only_tasks_for_validation_where_they_are_the_current_eic(): void
    {
        $mine = $this->forValidationTask('2026-11-21 09:00:00', ['equipment_name_snapshot' => 'Chiller 1', 'due_date' => '2026-11-19']);
        $this->forValidationTask('2026-11-21 09:00:00', ['site_id' => 'S-2', 'eic_employee_id' => 'P-OTHER']);
        $this->task(['status' => PmStatus::Scheduled]);
        $this->task(['status' => PmStatus::InProgress, 'started_at' => '2026-11-20 08:00:00']);
        $this->task(['status' => PmStatus::Returned]);

        $this->actingAs($this->eic())
            ->getJson('/api/me/waiting')
            ->assertOk()
            ->assertExactJson(['data' => [[
                'type' => 'pm_task',
                'id' => $mine->id,
                'title' => 'Chiller 1',
                'site' => ['id' => 'S-1', 'name' => 'North Plant'],
                'status' => 'for_validation',
                'priority' => null,
                'timeliness' => 'overdue',
                'waiting_since' => CarbonImmutable::parse('2026-11-21 09:00:00')->toJSON(),
            ]]]);
    }

    public function test_the_eic_on_the_task_record_is_history_only(): void
    {
        $this->forValidationTask('2026-11-21 09:00:00', ['site_id' => 'S-2', 'eic_employee_id' => 'P-EIC']);

        $this->actingAs($this->eic())->getJson('/api/me/waiting')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_a_replaced_eics_task_moves_to_the_new_eics_list(): void
    {
        $task = $this->forValidationTask('2026-11-21 09:00:00');
        $oldEic = $this->eic();
        $newEic = $this->eic('P-NEW');

        SiteSetting::query()->where('site_id', 'S-1')->update(['eic_employee_id' => 'P-NEW']);

        $this->actingAs($oldEic)->getJson('/api/me/waiting')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($newEic)->getJson('/api/me/waiting')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $task->id);
    }

    public function test_vendor_staff_never_act_as_eic_even_with_a_matching_employee_id(): void
    {
        $this->forValidationTask('2026-11-21 09:00:00');
        $staff = User::factory()->vendorStaff($this->vendor)->create(['employee_id' => 'P-EIC']);

        $this->actingAs($staff)->getJson('/api/me/waiting')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_vendor_staff_see_only_their_own_vendors_open_tasks(): void
    {
        $scheduled = $this->task(['status' => PmStatus::Scheduled]);
        $inProgress = $this->task(['status' => PmStatus::InProgress, 'started_at' => '2026-11-20 08:00:00']);
        $returned = $this->task(['status' => PmStatus::Returned]);
        $this->forValidationTask('2026-11-21 09:00:00');
        $this->task(['status' => PmStatus::Completed, 'work_done_date' => '2026-11-20']);
        $this->task(['status' => PmStatus::Cancelled]);
        $this->task(['status' => PmStatus::Scheduled, 'vendor_id' => Vendor::factory()->create()->id]);

        $response = $this->actingAs(User::factory()->vendorStaff($this->vendor)->create())
            ->getJson('/api/me/waiting')
            ->assertOk();

        $this->assertEqualsCanonicalizing(
            [$scheduled->id, $inProgress->id, $returned->id],
            array_column($response->json('data'), 'id'),
        );
    }

    public function test_a_completed_task_disappears_from_the_list(): void
    {
        $task = $this->forValidationTask('2026-11-21 09:00:00');
        $eic = $this->eic();

        $this->actingAs($eic)->getJson('/api/me/waiting')->assertJsonCount(1, 'data');

        $this->actingAs($eic)->postJson("/api/pm-tasks/{$task->id}/approve")->assertOk();

        $this->actingAs($eic)->getJson('/api/me/waiting')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_items_are_sorted_oldest_waiting_first_with_waiting_since_per_status(): void
    {
        Carbon::setTestNow('2026-11-10 08:00:00');
        $scheduled = $this->task(['status' => PmStatus::Scheduled]);

        Carbon::setTestNow('2026-11-12 08:00:00');
        $returned = $this->forValidationTask('2026-11-12 07:00:00');
        Carbon::setTestNow('2026-11-14 08:00:00');
        PmTask::returnReport($returned, $this->eic(), 'Missing photo.');

        $inProgress = $this->task(['status' => PmStatus::InProgress, 'started_at' => '2026-11-13 08:00:00']);

        Carbon::setTestNow('2026-11-22 10:00:00');

        $response = $this->actingAs(User::factory()->vendorStaff($this->vendor)->create())
            ->getJson('/api/me/waiting')
            ->assertOk();

        $this->assertSame([$scheduled->id, $inProgress->id, $returned->id], array_column($response->json('data'), 'id'));
        $this->assertSame([
            CarbonImmutable::parse('2026-11-10 08:00:00')->toJSON(),
            CarbonImmutable::parse('2026-11-13 08:00:00')->toJSON(),
            CarbonImmutable::parse('2026-11-14 08:00:00')->toJSON(),
        ], array_column($response->json('data'), 'waiting_since'));
    }

    public function test_for_validation_waits_since_the_latest_report_revision(): void
    {
        $task = $this->forValidationTask('2026-11-15 09:00:00');
        $this->submitReport($task, '2026-11-18 09:00:00', revision: 2);

        $this->actingAs($this->eic())->getJson('/api/me/waiting')
            ->assertJsonPath('data.0.waiting_since', CarbonImmutable::parse('2026-11-18 09:00:00')->toJSON());
    }

    public function test_ties_are_broken_by_type_then_id(): void
    {
        $this->app->tag([ZetaDummyProvider::class], WaitingItemProvider::TAG);

        $second = $this->forValidationTask('2026-11-21 09:00:00');
        $first = $this->forValidationTask('2026-11-21 09:00:00');
        $this->assertGreaterThan($second->id, $first->id);

        $response = $this->actingAs($this->eic())->getJson('/api/me/waiting')->assertOk();

        $this->assertSame(
            [['pm_task', $second->id], ['pm_task', $first->id], ['zeta', 1]],
            array_map(fn (array $item): array => [$item['type'], $item['id']], $response->json('data')),
        );
    }

    public function test_a_new_provider_is_listed_without_changing_the_endpoint(): void
    {
        $this->app->tag([DummyProvider::class], WaitingItemProvider::TAG);
        $task = $this->forValidationTask('2026-11-21 09:00:00');

        $response = $this->actingAs($this->eic())->getJson('/api/me/waiting')->assertOk();

        $this->assertSame([['dummy', 7], ['pm_task', $task->id]], array_map(
            fn (array $item): array => [$item['type'], $item['id']],
            $response->json('data'),
        ));
        $response->assertJsonPath('data.0.title', 'Dummy thing')
            ->assertJsonPath('data.0.site', ['id' => 'S-9', 'name' => 'Dummy Site'])
            ->assertJsonPath('data.0.timeliness', null);
    }

    public function test_the_query_count_does_not_grow_with_the_number_of_items(): void
    {
        $vendorStaff = User::factory()->vendorStaff($this->vendor)->create();
        $eic = $this->eic();
        $this->forValidationTask('2026-11-21 09:00:00');
        $this->task(['status' => PmStatus::Returned]);

        $countQueries = function (User $user): int {
            $this->actingAs($user);
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson('/api/me/waiting')->assertOk();
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };

        $eicBefore = $countQueries($eic);
        $vendorBefore = $countQueries($vendorStaff);

        foreach (range(1, 4) as $i) {
            $this->forValidationTask('2026-11-21 09:00:00');
            $this->task(['status' => PmStatus::Returned]);
        }

        $this->assertSame($eicBefore, $countQueries($eic));
        $this->assertSame($vendorBefore, $countQueries($vendorStaff));
    }
}

class DummyProvider implements WaitingItemProvider
{
    public function itemsFor(User $user): iterable
    {
        yield new WaitingItem('dummy', 7, 'Dummy thing', 'S-9', 'Dummy Site', 'pending', null, CarbonImmutable::parse('2026-11-01 00:00:00'));
    }
}

class ZetaDummyProvider implements WaitingItemProvider
{
    public function itemsFor(User $user): iterable
    {
        yield new WaitingItem('zeta', 1, 'Zeta', 'S-9', 'Dummy Site', 'pending', null, CarbonImmutable::parse('2026-11-21 09:00:00'));
    }
}
