<?php

namespace Tests\Feature;

use App\Enums\ECallPriority;
use App\Enums\ECallStatus;
use App\Enums\PmStatus;
use App\Enums\WorkOrderStatus;
use App\MasterData\Data\Equipment;
use App\MasterData\InMemoryMasterDataDirectory;
use App\MasterData\MasterDataDirectory;
use App\Models\ECall;
use App\Models\PmTask;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Equipment maintenance history (issue 26): one read-only timeline of an Equipment's PM Tasks, E-Calls and Work
 * Orders, newest first by entry date, built from PSS's own records and snapshots only.
 */
class EquipmentHistoryApiTest extends TestCase
{
    use RefreshDatabase;

    private Vendor $vendor;

    private User $internal;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-24 09:00:00');

        $this->vendor = Vendor::factory()->create();
        $this->internal = User::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * @param  array<string, string>  $query
     */
    private function history(string $equipmentId = 'EQ-1', array $query = [], ?User $as = null): TestResponse
    {
        $url = '/api/equipment/'.$equipmentId.'/history'.($query === [] ? '' : '?'.http_build_query($query));

        return $this->actingAs($as ?? $this->internal)->getJson($url);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function pmTask(string $dueDate, array $attributes = []): PmTask
    {
        return PmTask::factory()->create([
            'equipment_id' => 'EQ-1',
            'equipment_name_snapshot' => 'Genset A',
            'equipment_category_snapshot' => 'Generator',
            'site_id' => 'S-1',
            'site_name_snapshot' => 'Makati Site',
            'vendor_id' => $this->vendor->id,
            'due_from_date' => Carbon::parse($dueDate)->subMonth()->toDateString(),
            'due_date' => $dueDate,
            // Closed by default: at most one PM Task per Equipment may be open.
            'status' => PmStatus::Completed,
            'work_done_date' => $dueDate,
            ...$attributes,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function eCall(string $createdAt, array $attributes = []): ECall
    {
        $eCall = ECall::factory()->create([
            'equipment_id' => 'EQ-1',
            'equipment_name_snapshot' => 'Genset A',
            'equipment_category_snapshot' => 'Generator',
            'site_id' => 'S-1',
            'site_name_snapshot' => 'Makati Site',
            'vendor_id' => $this->vendor->id,
            'priority' => ECallPriority::cases()[0],
            ...$attributes,
        ]);
        $this->setCreatedAt('e_calls', $eCall->id, $createdAt);

        return $eCall->refresh();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function workOrder(ECall $eCall, string $createdAt, array $attributes = []): WorkOrder
    {
        $proposal = $eCall->proposals()->create([
            'revision' => 1,
            'assessment_notes' => 'Replace part.',
            'fsr_disk' => 'local',
            'fsr_path' => 'fsr.pdf',
            'fsr_original_name' => 'fsr.pdf',
            'fsr_mime_type' => 'application/pdf',
            'fsr_size_bytes' => 100,
            'total_millicentavos' => 12_345_650,
            'total_centavos' => 12_346,
            'submitted_by_user_id' => User::factory()->vendorStaff($eCall->vendor)->create()->id,
            'submitted_at' => now(),
        ]);
        $workOrder = WorkOrder::create([
            'e_call_id' => $eCall->id,
            'e_call_proposal_id' => $proposal->id,
            'status' => WorkOrderStatus::Open,
            'created_by_user_id' => $this->internal->id,
            'estimated_amount_millicentavos' => 12_345_650,
            'estimated_amount_centavos' => 12_346,
            ...$attributes,
        ]);
        $this->setCreatedAt('work_orders', $workOrder->id, $createdAt);

        return $workOrder->refresh();
    }

    private function setCreatedAt(string $table, int $id, string $createdAt): void
    {
        DB::table($table)->where('id', $id)->update(['created_at' => $createdAt]);
    }

    private function report(PmTask $task, int $revision, ?string $findings): void
    {
        $task->reports()->create([
            'revision' => $revision,
            'work_done_date' => '2026-03-01',
            'findings' => $findings,
            'submitted_by_user_id' => User::factory()->vendorStaff($this->vendor)->create()->id,
            'submitted_at' => now(),
        ]);
    }

    public function test_returns_pm_tasks_e_calls_and_work_orders_newest_first_by_entry_date(): void
    {
        $pm = $this->pmTask('2026-03-10');
        $older = $this->eCall('2026-02-01 10:00:00');
        $newer = $this->eCall('2026-04-05 08:00:00');
        $workOrder = $this->workOrder($newer, '2026-05-01 09:00:00');

        $this->history()
            ->assertOk()
            ->assertJsonCount(4, 'data')
            ->assertJsonPath('data.0.type', 'work_order')
            ->assertJsonPath('data.0.id', $workOrder->id)
            ->assertJsonPath('data.0.entry_date', '2026-05-01')
            ->assertJsonPath('data.1.type', 'e_call')
            ->assertJsonPath('data.1.id', $newer->id)
            ->assertJsonPath('data.1.entry_date', '2026-04-05')
            ->assertJsonPath('data.2.type', 'pm_task')
            ->assertJsonPath('data.2.id', $pm->id)
            ->assertJsonPath('data.2.entry_date', '2026-03-10')
            ->assertJsonPath('data.3.type', 'e_call')
            ->assertJsonPath('data.3.id', $older->id);
    }

    public function test_ties_on_entry_date_put_the_newer_created_at_first_then_the_higher_id(): void
    {
        $first = $this->eCall('2026-04-05 08:00:00');
        $later = $this->eCall('2026-04-05 15:00:00');
        $sameTimeLow = $this->eCall('2026-04-05 11:00:00');
        $sameTimeHigh = $this->eCall('2026-04-05 11:00:00');

        $ids = collect($this->history()->assertOk()->json('data'))->pluck('id')->all();

        $this->assertSame([$later->id, $sameTimeHigh->id, $sameTimeLow->id, $first->id], $ids);
    }

    public function test_e_call_entry_date_is_the_manila_date_it_was_filed(): void
    {
        // Stored timestamps are Manila wall-clock time (ADR 0003): 00:30 on the 5th is the 5th, not the 4th as in UTC.
        $this->eCall('2026-04-05 00:30:00');

        $this->history()->assertOk()->assertJsonPath('data.0.entry_date', '2026-04-05');
    }

    public function test_excludes_records_of_other_equipment_and_e_calls_without_equipment(): void
    {
        $this->pmTask('2026-03-10', ['equipment_id' => 'EQ-2']);
        $other = $this->eCall('2026-04-05 08:00:00', ['equipment_id' => 'EQ-2']);
        $this->workOrder($other, '2026-05-01 09:00:00');
        $this->eCall('2026-04-06 08:00:00', ['equipment_id' => null, 'equipment_name_snapshot' => null]);
        $mine = $this->eCall('2026-04-07 08:00:00');

        $this->history()
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mine->id);
    }

    public function test_includes_records_in_every_status_including_cancelled_and_rejected(): void
    {
        $this->pmTask('2026-01-10', ['status' => PmStatus::Cancelled]);
        $this->pmTask('2026-02-10', ['status' => PmStatus::Completed, 'work_done_date' => '2026-02-09']);
        $this->eCall('2026-03-01 08:00:00', ['status' => ECallStatus::Rejected]);
        $this->eCall('2026-03-02 08:00:00', ['status' => ECallStatus::Cancelled]);
        $this->workOrder($this->eCall('2026-03-03 08:00:00', ['status' => ECallStatus::Closed]), '2026-03-04 08:00:00', ['status' => WorkOrderStatus::Rejected]);
        $this->workOrder($this->eCall('2026-03-05 08:00:00', ['status' => ECallStatus::Closed]), '2026-03-06 08:00:00', ['status' => WorkOrderStatus::Cancelled]);

        $statuses = collect($this->history()->assertOk()->json('data'))->map(fn (array $entry): string => $entry['type'].':'.$entry['status'])->all();

        $this->assertSame([
            'work_order:cancelled', 'e_call:closed', 'work_order:rejected', 'e_call:closed',
            'e_call:cancelled', 'e_call:rejected', 'pm_task:completed', 'pm_task:cancelled',
        ], $statuses);
    }

    public function test_pm_task_entry_shows_work_done_date_and_findings_of_the_latest_report_revision(): void
    {
        $task = $this->pmTask('2026-03-10', ['status' => PmStatus::Completed, 'work_done_date' => '2026-03-08']);
        $this->report($task, 1, 'Old findings.');
        $this->report($task, 2, 'Belt worn; replace next visit.');

        $this->history()
            ->assertOk()
            ->assertJsonPath('data.0.type', 'pm_task')
            ->assertJsonPath('data.0.status', 'completed')
            ->assertJsonPath('data.0.work_done_date', '2026-03-08')
            ->assertJsonPath('data.0.findings', 'Belt worn; replace next visit.');
    }

    public function test_pm_task_without_a_report_has_null_work_done_date_and_findings(): void
    {
        $this->pmTask('2026-03-10', ['status' => PmStatus::Scheduled, 'work_done_date' => null]);

        $this->history()
            ->assertOk()
            ->assertJsonPath('data.0.status', 'scheduled')
            ->assertJsonPath('data.0.work_done_date', null)
            ->assertJsonPath('data.0.findings', null);
    }

    public function test_e_call_entry_shows_priority_status_and_the_pm_task_it_was_filed_from(): void
    {
        $task = $this->pmTask('2026-03-10');
        $fromPm = $this->eCall('2026-03-12 08:00:00', ['pm_task_id' => $task->id, 'status' => ECallStatus::ForEicReview]);
        $plain = $this->eCall('2026-03-11 08:00:00');

        $this->history()
            ->assertOk()
            ->assertJsonPath('data.0.id', $fromPm->id)
            ->assertJsonPath('data.0.priority', $fromPm->priority->value)
            ->assertJsonPath('data.0.status', 'for_eic_review')
            ->assertJsonPath('data.0.pm_task_id', $task->id)
            ->assertJsonPath('data.1.id', $plain->id)
            ->assertJsonPath('data.1.pm_task_id', null);
    }

    public function test_work_order_entry_shows_its_e_call_and_exact_money_with_billing_null_until_set(): void
    {
        $unbilled = $this->workOrder($this->eCall('2026-03-01 08:00:00'), '2026-03-02 08:00:00');
        $billed = $this->workOrder($this->eCall('2026-03-03 08:00:00'), '2026-03-04 08:00:00', [
            'status' => WorkOrderStatus::Closed,
            'billing_amount_millicentavos' => 10_000_500,
            'billing_amount_centavos' => 10_001,
        ]);

        $this->history()
            ->assertOk()
            ->assertJsonPath('data.0.id', $billed->id)
            ->assertJsonPath('data.0.e_call_id', $billed->e_call_id)
            ->assertJsonPath('data.0.estimated_amount', '123.46')
            ->assertJsonPath('data.0.estimated_amount_exact', '123.45650')
            ->assertJsonPath('data.0.billing_amount', '100.01')
            ->assertJsonPath('data.0.billing_amount_exact', '100.00500')
            ->assertJsonPath('data.2.id', $unbilled->id)
            ->assertJsonPath('data.2.e_call_id', $unbilled->e_call_id)
            ->assertJsonPath('data.2.billing_amount', null)
            ->assertJsonPath('data.2.billing_amount_exact', null);
    }

    public function test_entries_carry_the_stored_snapshots_and_a_work_order_uses_its_e_calls(): void
    {
        $this->pmTask('2026-03-10');
        $this->workOrder($this->eCall('2026-03-01 08:00:00'), '2026-03-02 08:00:00');

        $response = $this->history()->assertOk();

        foreach ($response->json('data') as $entry) {
            $this->assertSame('EQ-1', $entry['equipment_id']);
            $this->assertSame('Genset A', $entry['equipment_name_snapshot']);
            $this->assertSame('Generator', $entry['equipment_category_snapshot']);
            $this->assertSame('S-1', $entry['site_id']);
            $this->assertSame('Makati Site', $entry['site_name_snapshot']);
        }
    }

    public function test_history_of_equipment_removed_from_the_directory_still_shows_with_the_snapshot_name(): void
    {
        $directory = new InMemoryMasterDataDirectory;
        $directory->addEquipment(new Equipment(id: 'EQ-1', name: 'Genset A (live)', siteId: 'S-1', categoryId: 'CAT-1'));
        $this->app->instance(MasterDataDirectory::class, $directory);
        $this->pmTask('2026-03-10');
        $this->workOrder($this->eCall('2026-03-01 08:00:00'), '2026-03-02 08:00:00');
        $before = $this->history()->assertOk()->json();

        // The Equipment is gone upstream: a directory without it returns the same history.
        $this->app->instance(MasterDataDirectory::class, new InMemoryMasterDataDirectory);
        $after = $this->history()->assertOk()->assertJsonCount(3, 'data')->assertJsonPath('data.0.equipment_name_snapshot', 'Genset A')->json();

        $this->assertSame($before, $after);
    }

    public function test_never_calls_the_master_data_directory(): void
    {
        $this->pmTask('2026-03-10');
        $this->workOrder($this->eCall('2026-03-01 08:00:00'), '2026-03-02 08:00:00');
        // A mock with no expectations fails on any call.
        $this->mock(MasterDataDirectory::class);

        $this->history()->assertOk()->assertJsonCount(3, 'data');
    }

    public function test_from_narrows_by_entry_date_inclusive(): void
    {
        $this->pmTask('2026-03-09');
        $pm = $this->pmTask('2026-03-10');
        $this->eCall('2026-03-09 23:59:59');
        $eCall = $this->eCall('2026-03-10 00:00:00');
        $workOrder = $this->workOrder($this->eCall('2026-03-01 08:00:00'), '2026-03-10 00:00:00');

        $entries = collect($this->history(query: ['from' => '2026-03-10'])->assertOk()->json('data'))
            ->map(fn (array $entry): string => $entry['type'].':'.$entry['id'])->sort()->values()->all();

        $this->assertSame(collect(['pm_task:'.$pm->id, 'e_call:'.$eCall->id, 'work_order:'.$workOrder->id])->sort()->values()->all(), $entries);
    }

    public function test_to_narrows_by_entry_date_inclusive(): void
    {
        $pm = $this->pmTask('2026-03-10');
        $this->pmTask('2026-03-11');
        $eCall = $this->eCall('2026-03-10 23:59:59');
        $this->eCall('2026-03-11 00:00:00');
        $this->workOrder($eCall, '2026-03-11 08:00:00');

        $entries = collect($this->history(query: ['to' => '2026-03-10'])->assertOk()->json('data'))
            ->map(fn (array $entry): string => $entry['type'].':'.$entry['id'])->all();

        // Same entry date: the PM Task row was created later (test now), so it comes first.
        $this->assertSame(['pm_task:'.$pm->id, 'e_call:'.$eCall->id], $entries);
    }

    public function test_from_and_to_together_on_the_same_day(): void
    {
        $this->pmTask('2026-03-09');
        $pm = $this->pmTask('2026-03-10');
        $this->pmTask('2026-03-11');

        $this->history(query: ['from' => '2026-03-10', 'to' => '2026-03-10'])
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $pm->id);
    }

    /**
     * @return array<string, array{array<string, string>, string}>
     */
    public static function invalidFilters(): array
    {
        return [
            'from after to' => [['from' => '2026-03-11', 'to' => '2026-03-10'], 'to'],
            'bad from' => [['from' => '2026-02-30'], 'from'],
            'bad to' => [['to' => 'yesterday'], 'to'],
            'wrong format' => [['from' => '10/03/2026'], 'from'],
        ];
    }

    /**
     * @param  array<string, string>  $query
     */
    #[DataProvider('invalidFilters')]
    public function test_invalid_date_filters_return_422(array $query, string $field): void
    {
        $this->history(query: $query)->assertUnprocessable()->assertJsonValidationErrors($field);
    }

    public function test_vendor_staff_see_only_their_own_vendors_records(): void
    {
        $otherVendor = Vendor::factory()->create();
        $mine = $this->pmTask('2026-03-10');
        $this->pmTask('2026-03-11', ['vendor_id' => $otherVendor->id]);
        $myECall = $this->eCall('2026-03-12 08:00:00');
        $myWorkOrder = $this->workOrder($myECall, '2026-03-13 08:00:00');
        $theirs = $this->eCall('2026-03-14 08:00:00', ['vendor_id' => $otherVendor->id]);
        $this->workOrder($theirs, '2026-03-15 08:00:00');

        $staff = User::factory()->vendorStaff($this->vendor)->create();
        $entries = collect($this->history(as: $staff)->assertOk()->json('data'))
            ->map(fn (array $entry): string => $entry['type'].':'.$entry['id'])->all();

        $this->assertSame(['work_order:'.$myWorkOrder->id, 'e_call:'.$myECall->id, 'pm_task:'.$mine->id], $entries);
        $this->history()->assertOk()->assertJsonCount(6, 'data');
    }

    public function test_equipment_with_no_records_returns_an_empty_list(): void
    {
        $this->history('EQ-NONE')->assertOk()->assertExactJson(['data' => []]);
    }

    public function test_guests_get_401(): void
    {
        $this->getJson('/api/equipment/EQ-1/history')->assertUnauthorized();
    }

    public function test_query_count_does_not_grow_with_the_number_of_records(): void
    {
        $task = $this->pmTask('2026-03-10');
        $this->report($task, 1, 'Fine.');
        $this->workOrder($this->eCall('2026-03-01 08:00:00'), '2026-03-02 08:00:00');
        $baseline = $this->countQueries();

        foreach (range(1, 4) as $i) {
            $more = $this->pmTask('2026-04-0'.$i);
            $this->report($more, 1, 'Findings '.$i);
            $this->report($more, 2, 'Findings '.$i.' rev 2');
            $this->workOrder($this->eCall('2026-05-0'.$i.' 08:00:00'), '2026-06-0'.$i.' 08:00:00');
        }

        $this->assertSame($baseline, $this->countQueries());
    }

    private function countQueries(): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->history()->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }
}
