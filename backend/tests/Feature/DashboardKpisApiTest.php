<?php

namespace Tests\Feature;

use App\Enums\ECallPriority;
use App\Enums\ECallStatus;
use App\Enums\PmStatus;
use App\MasterData\Data\Equipment;
use App\MasterData\Data\Site;
use App\MasterData\InMemoryMasterDataDirectory;
use App\MasterData\MasterDataDirectory;
use App\Models\ChecklistItem;
use App\Models\ChecklistTemplate;
use App\Models\ECall;
use App\Models\PmTask;
use App\Models\SiteSetting;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Dashboard KPIs (issue 28): PM Compliance, operational status and Equipment Requiring Attention on /api/dashboard.
 * The clock is frozen at 2026-11-20 10:00 in Manila, so the default period is November 2026.
 */
class DashboardKpisApiTest extends TestCase
{
    use RefreshDatabase;

    private InMemoryMasterDataDirectory $directory;

    private Vendor $vendor;

    private Vendor $otherVendor;

    private User $internal;

    private ChecklistItem $item;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(now('Asia/Manila')->setDate(2026, 11, 20)->setTime(10, 0));

        $directory = $this->app->make(MasterDataDirectory::class);
        $this->assertInstanceOf(InMemoryMasterDataDirectory::class, $directory);
        $this->directory = $directory;
        $this->vendor = Vendor::factory()->create();
        $this->otherVendor = Vendor::factory()->create();
        $this->internal = User::factory()->create();

        $this->site('S-1', $this->vendor);
        $this->site('S-2', $this->otherVendor);
        $this->directory->addSite(new Site('S-3', 'Site without settings'));

        $this->item = ChecklistTemplate::create(['key' => 'genset', 'name' => 'Genset PM', 'equipment_category_id' => 'CAT-1', 'is_active' => true])
            ->versions()->create(['version_number' => 1, 'content_fingerprint' => str_repeat('a', 64)])
            ->items()->create(['position' => 1, 'label' => 'Oil level', 'response_type' => 'text']);
    }

    private function site(string $id, Vendor $vendor): void
    {
        $this->directory->addSite(new Site($id, "Site {$id}"));
        SiteSetting::factory()->create(['site_id' => $id, 'vendor_id' => $vendor->id]);
    }

    private function equipment(string $id, string $siteId, ?string $operatingStatus = 'operational', string $categoryId = 'CAT-1'): void
    {
        $this->directory->addEquipment(new Equipment($id, $siteId, "Equipment {$id}", $categoryId, operatingStatus: $operatingStatus));
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function dashboard(array $query = [], ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->internal)
            ->getJson('/api/dashboard'.($query === [] ? '' : '?'.http_build_query($query)));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function pmTask(string $equipmentId, PmStatus $status, string $dueDate, ?string $workDoneDate = null, array $attributes = []): PmTask
    {
        $equipment = $this->directory->findEquipment($equipmentId);

        return PmTask::factory()->create([
            'equipment_id' => $equipmentId,
            'site_id' => $equipment !== null ? $equipment->siteId : 'S-1',
            'equipment_category_id' => $equipment !== null ? $equipment->categoryId : 'CAT-1',
            'vendor_id' => $this->vendor->id,
            'status' => $status,
            'due_from_date' => $dueDate,
            'due_date' => $dueDate,
            'work_done_date' => $workDoneDate,
            ...$attributes,
        ]);
    }

    /**
     * Add a PM Report revision whose single response is failed or not.
     */
    private function report(PmTask $task, int $revision, bool $failed): void
    {
        $report = $task->reports()->create([
            'revision' => $revision,
            'work_done_date' => $task->work_done_date ?? $task->due_date,
            'submitted_by_user_id' => $this->internal->id,
            'submitted_at' => now(),
        ]);
        $report->responses()->create(['checklist_item_id' => $this->item->id, 'value' => 'x', 'remarks' => null, 'is_failed' => $failed]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function eCall(string $equipmentId, ECallPriority $priority, ECallStatus $status, array $attributes = []): void
    {
        $equipment = $this->directory->findEquipment($equipmentId);
        ECall::factory()->create([
            'equipment_id' => $equipmentId,
            'site_id' => $equipment !== null ? $equipment->siteId : 'S-1',
            'equipment_category_id' => $equipment !== null ? $equipment->categoryId : 'CAT-1',
            'vendor_id' => $this->vendor->id,
            'priority' => $priority,
            'status' => $status,
            ...$attributes,
        ]);
    }

    // PM Compliance

    public function test_pm_compliance_covers_the_current_manila_month_up_to_today(): void
    {
        $this->pmTask('EQ-1', PmStatus::Completed, '2026-11-05', '2026-11-05');
        $this->pmTask('EQ-1', PmStatus::Completed, '2026-11-10', '2026-11-12');
        $this->pmTask('EQ-1', PmStatus::ForValidation, '2026-11-15', '2026-11-14');
        $this->pmTask('EQ-2', PmStatus::Cancelled, '2026-11-16');
        $this->pmTask('EQ-2', PmStatus::Completed, '2026-10-31', '2026-10-31');
        $this->pmTask('EQ-3', PmStatus::Scheduled, '2026-11-21');

        $this->dashboard()->assertOk()->assertJsonPath('data.pm.compliance', [
            'period_from' => '2026-11-01',
            'period_to' => '2026-11-30',
            'due' => 3,
            'on_time' => 1,
            'percent' => 33.3,
        ]);
    }

    public function test_pm_compliance_uses_the_manila_date_not_utc(): void
    {
        // 2026-11-30 23:30 UTC is already 2026-12-01 in Manila.
        $this->travelTo(now('UTC')->setDate(2026, 11, 30)->setTime(23, 30));

        $this->dashboard()->assertOk()
            ->assertJsonPath('data.pm.compliance.period_from', '2026-12-01')
            ->assertJsonPath('data.pm.compliance.period_to', '2026-12-31')
            ->assertJsonPath('data.pm.compliance.due', 0)
            ->assertJsonPath('data.pm.compliance.percent', null);
    }

    public function test_pm_compliance_is_narrowed_by_site_category_and_vendor(): void
    {
        $this->pmTask('EQ-A', PmStatus::Completed, '2026-11-05', '2026-11-05', ['site_id' => 'S-1', 'equipment_category_id' => 'CAT-1']);
        $this->pmTask('EQ-B', PmStatus::Completed, '2026-11-05', '2026-11-06', ['site_id' => 'S-2', 'equipment_category_id' => 'CAT-1']);
        $this->pmTask('EQ-C', PmStatus::Scheduled, '2026-11-05', null, ['site_id' => 'S-1', 'equipment_category_id' => 'CAT-2']);
        $this->pmTask('EQ-D', PmStatus::Scheduled, '2026-11-05', null, ['site_id' => 'S-1', 'equipment_category_id' => 'CAT-1', 'vendor_id' => $this->otherVendor->id]);

        $this->dashboard()->assertJsonPath('data.pm.compliance.due', 4);
        $this->dashboard(['site_id' => 'S-1'])->assertJsonPath('data.pm.compliance.due', 3);
        $this->dashboard(['equipment_category_id' => 'CAT-2'])->assertJsonPath('data.pm.compliance.due', 1);
        $this->dashboard(['vendor_id' => $this->otherVendor->id])->assertJsonPath('data.pm.compliance.due', 1);
        $this->dashboard(['site_id' => 'S-1', 'equipment_category_id' => 'CAT-1', 'vendor_id' => $this->vendor->id])
            ->assertJsonPath('data.pm.compliance.due', 1)
            ->assertJsonPath('data.pm.compliance.on_time', 1)
            ->assertJsonPath('data.pm.compliance.percent', 100);
    }

    // Operational status

    public function test_counts_operating_status_over_the_covered_equipment(): void
    {
        $this->equipment('EQ-1', 'S-1', 'Operational');
        $this->equipment('EQ-2', 'S-1', 'down');
        $this->equipment('EQ-3', 'S-1', null);
        $this->equipment('EQ-4', 'S-2', ' operational ');
        $this->equipment('EQ-5', 'S-3', '');

        $this->dashboard()->assertOk()->assertJsonPath('data.equipment.operating', [
            'operational' => 2, 'non_operational' => 1, 'unknown' => 2,
        ]);
        $this->dashboard(['site_id' => 'S-1'])->assertJsonPath('data.equipment.operating', [
            'operational' => 1, 'non_operational' => 1, 'unknown' => 1,
        ]);
    }

    public function test_equipment_figures_are_narrowed_by_category_and_site_vendor(): void
    {
        $this->equipment('EQ-1', 'S-1');
        $this->equipment('EQ-2', 'S-1', 'operational', 'CAT-2');
        $this->equipment('EQ-3', 'S-2');
        $this->equipment('EQ-4', 'S-3');

        $this->dashboard(['equipment_category_id' => 'CAT-2'])->assertJsonPath('data.equipment.operating.operational', 1);
        $this->dashboard(['vendor_id' => $this->vendor->id])->assertJsonPath('data.equipment.operating.operational', 2);
        // S-3 has no site_settings row, so no Site Vendor: any vendor filter leaves it out.
        $this->dashboard(['vendor_id' => $this->otherVendor->id])->assertJsonPath('data.equipment.operating.operational', 1);
        $this->dashboard(['site_id' => 'NO-SUCH-SITE'])->assertJsonPath('data.equipment.operating', [
            'operational' => 0, 'non_operational' => 0, 'unknown' => 0,
        ]);
    }

    // Equipment Requiring Attention

    public function test_lists_equipment_requiring_attention_with_its_reasons_sorted_by_site_then_equipment(): void
    {
        $this->equipment('EQ-9', 'S-1', 'down');
        $this->equipment('EQ-1', 'S-2');
        $this->equipment('EQ-2', 'S-1');
        $this->equipment('EQ-3', 'S-1');
        $this->pmTask('EQ-9', PmStatus::Scheduled, '2026-11-19');
        $this->pmTask('EQ-1', PmStatus::InProgress, '2026-11-10');
        $this->eCall('EQ-2', ECallPriority::Emergency, ECallStatus::ForVendorAssessment);
        $this->pmTask('EQ-3', PmStatus::Scheduled, '2026-11-20');

        $this->dashboard()->assertOk()->assertJsonPath('data.equipment.requiring_attention', [
            'count' => 3,
            'items' => [
                ['equipment_id' => 'EQ-2', 'equipment_name' => 'Equipment EQ-2', 'site_id' => 'S-1', 'reasons' => ['emergency_e_call']],
                ['equipment_id' => 'EQ-9', 'equipment_name' => 'Equipment EQ-9', 'site_id' => 'S-1', 'reasons' => ['overdue_pm', 'not_operating']],
                ['equipment_id' => 'EQ-1', 'equipment_name' => 'Equipment EQ-1', 'site_id' => 'S-2', 'reasons' => ['overdue_pm']],
            ],
        ]);
    }

    public function test_failed_items_come_from_the_latest_revision_of_the_most_recent_completed_task(): void
    {
        $this->equipment('EQ-1', 'S-1');
        $this->equipment('EQ-2', 'S-1');
        $this->equipment('EQ-3', 'S-1');

        // EQ-1: the older completed task failed, the most recent one is clean.
        $this->report($this->pmTask('EQ-1', PmStatus::Completed, '2026-09-01', '2026-09-01'), 1, true);
        $this->report($this->pmTask('EQ-1', PmStatus::Completed, '2026-10-01', '2026-10-01'), 1, false);
        // EQ-2: revision 1 failed, revision 2 (the latest) is clean; then the reverse on EQ-3.
        $task = $this->pmTask('EQ-2', PmStatus::Completed, '2026-10-01', '2026-10-01');
        $this->report($task, 1, true);
        $this->report($task, 2, false);
        $task = $this->pmTask('EQ-3', PmStatus::Completed, '2026-10-01', '2026-10-01');
        $this->report($task, 1, false);
        $this->report($task, 2, true);
        // A failed report on a task that isn't Completed yet doesn't count.
        $this->report($this->pmTask('EQ-1', PmStatus::ForValidation, '2026-11-25', '2026-11-19'), 1, true);

        $this->dashboard()->assertOk()
            ->assertJsonPath('data.equipment.requiring_attention.count', 1)
            ->assertJsonPath('data.equipment.requiring_attention.items.0.equipment_id', 'EQ-3')
            ->assertJsonPath('data.equipment.requiring_attention.items.0.reasons', ['failed_items']);
    }

    public function test_only_open_emergency_e_calls_count(): void
    {
        $this->equipment('EQ-1', 'S-1');
        $this->eCall('EQ-1', ECallPriority::High, ECallStatus::ForVendorAssessment);
        foreach ([ECallStatus::Closed, ECallStatus::Rejected, ECallStatus::Cancelled] as $closed) {
            $this->eCall('EQ-1', ECallPriority::Emergency, $closed);
        }

        $this->dashboard()->assertJsonPath('data.equipment.requiring_attention', ['count' => 0, 'items' => []]);
    }

    public function test_requiring_attention_is_narrowed_by_site_and_category(): void
    {
        $this->equipment('EQ-1', 'S-1', 'down');
        $this->equipment('EQ-2', 'S-1', 'down', 'CAT-2');
        $this->equipment('EQ-3', 'S-2', 'down');

        $this->dashboard(['site_id' => 'S-2'])->assertJsonPath('data.equipment.requiring_attention.items.*.equipment_id', ['EQ-3']);
        $this->dashboard(['equipment_category_id' => 'CAT-2'])->assertJsonPath('data.equipment.requiring_attention.items.*.equipment_id', ['EQ-2']);
    }

    public function test_vendor_staff_cannot_see_another_vendors_equipment_in_requiring_attention(): void
    {
        $this->equipment('EQ-1', 'S-1', 'down');
        $this->equipment('EQ-2', 'S-2', 'down');
        $this->equipment('EQ-3', 'S-3', 'down');
        $this->pmTask('EQ-2', PmStatus::Scheduled, '2026-11-05', null, ['vendor_id' => $this->otherVendor->id]);
        $staff = User::factory()->vendorStaff($this->vendor)->create();

        foreach ([[], ['vendor_id' => $this->otherVendor->id]] as $query) {
            $this->dashboard($query, $staff)->assertOk()
                ->assertJsonPath('data.equipment.requiring_attention.items.*.equipment_id', ['EQ-1'])
                ->assertJsonPath('data.equipment.operating.non_operational', 1)
                ->assertJsonPath('data.pm.compliance.due', 0);
        }
    }

    /**
     * EQ-1 sits at the Vendor's own Site S-1, but every record on it belongs to another Vendor (say the Site's
     * previous Vendor): an overdue PM Task, a failed latest PM Report and an open Emergency E-Call. EQ-2 is down.
     */
    private function recordsOfAnotherVendorAtOwnSite(): void
    {
        $other = ['vendor_id' => $this->otherVendor->id];
        $this->equipment('EQ-1', 'S-1');
        $this->equipment('EQ-2', 'S-1', 'down');
        $this->pmTask('EQ-1', PmStatus::Scheduled, '2026-11-05', null, $other);
        $this->report($this->pmTask('EQ-1', PmStatus::Completed, '2026-10-01', '2026-10-01', $other), 1, true);
        $this->eCall('EQ-1', ECallPriority::Emergency, ECallStatus::ForVendorAssessment, $other);
        $this->pmTask('EQ-2', PmStatus::Scheduled, '2026-11-05', null, $other);
    }

    public function test_vendor_staff_get_no_reasons_from_another_vendors_records_but_internal_users_do(): void
    {
        $this->recordsOfAnotherVendorAtOwnSite();
        $staff = User::factory()->vendorStaff($this->vendor)->create();

        $this->dashboard([], $staff)->assertOk()->assertJsonPath('data.equipment.requiring_attention', [
            'count' => 1,
            'items' => [
                ['equipment_id' => 'EQ-2', 'equipment_name' => 'Equipment EQ-2', 'site_id' => 'S-1', 'reasons' => ['not_operating']],
            ],
        ]);

        $this->dashboard()->assertOk()->assertJsonPath('data.equipment.requiring_attention', [
            'count' => 2,
            'items' => [
                ['equipment_id' => 'EQ-1', 'equipment_name' => 'Equipment EQ-1', 'site_id' => 'S-1', 'reasons' => ['overdue_pm', 'failed_items', 'emergency_e_call']],
                ['equipment_id' => 'EQ-2', 'equipment_name' => 'Equipment EQ-2', 'site_id' => 'S-1', 'reasons' => ['overdue_pm', 'not_operating']],
            ],
        ]);
    }

    public function test_an_internal_users_vendor_filter_narrows_the_reasons_the_same_way(): void
    {
        $this->recordsOfAnotherVendorAtOwnSite();

        $this->dashboard(['vendor_id' => $this->vendor->id])->assertOk()
            ->assertJsonPath('data.equipment.requiring_attention.items.*.equipment_id', ['EQ-2'])
            ->assertJsonPath('data.equipment.requiring_attention.items.0.reasons', ['not_operating']);
    }

    public function test_vendor_staff_failed_items_use_the_latest_completed_task_of_their_own_vendor(): void
    {
        $this->equipment('EQ-1', 'S-1');
        $this->equipment('EQ-2', 'S-1');
        $other = ['vendor_id' => $this->otherVendor->id];
        // EQ-1: own Vendor's task failed; another Vendor's later task is clean.
        $this->report($this->pmTask('EQ-1', PmStatus::Completed, '2026-09-01', '2026-09-01'), 1, true);
        $this->report($this->pmTask('EQ-1', PmStatus::Completed, '2026-10-01', '2026-10-01', $other), 1, false);
        // EQ-2: own Vendor's task is clean; another Vendor's later task failed.
        $this->report($this->pmTask('EQ-2', PmStatus::Completed, '2026-09-01', '2026-09-01'), 1, false);
        $this->report($this->pmTask('EQ-2', PmStatus::Completed, '2026-10-01', '2026-10-01', $other), 1, true);
        $staff = User::factory()->vendorStaff($this->vendor)->create();

        $this->dashboard([], $staff)->assertOk()
            ->assertJsonPath('data.equipment.requiring_attention.items.*.equipment_id', ['EQ-1'])
            ->assertJsonPath('data.equipment.requiring_attention.items.0.reasons', ['failed_items']);
        $this->dashboard()->assertOk()
            ->assertJsonPath('data.equipment.requiring_attention.items.*.equipment_id', ['EQ-2']);
    }

    // Regression and performance

    public function test_the_issue_27_keys_are_still_returned_unchanged(): void
    {
        $this->equipment('EQ-1', 'S-1');
        $this->pmTask('EQ-1', PmStatus::InProgress, '2026-11-25');
        $this->eCall('EQ-1', ECallPriority::Normal, ECallStatus::ForEicReview);

        $response = $this->dashboard()->assertOk()
            ->assertJsonPath('data.pm.by_status.in_progress', 1)
            ->assertJsonPath('data.e_calls.by_status.for_eic_review', 1)
            ->assertJsonPath('data.e_calls.open', 1)
            ->assertJsonPath('data.work_orders.groups.closed', 0);

        $this->assertSame(['pm', 'e_calls', 'work_orders', 'equipment'], array_keys($response->json('data')));
        $this->assertSame(['by_status', 'compliance'], array_keys($response->json('data.pm')));
        $this->assertSame(['by_status', 'open'], array_keys($response->json('data.e_calls')));
        $this->assertSame(['by_status', 'groups'], array_keys($response->json('data.work_orders')));
        $this->assertSame(['operating', 'requiring_attention'], array_keys($response->json('data.equipment')));
    }

    public function test_the_query_count_does_not_grow_with_the_number_of_equipment(): void
    {
        $this->assertSame($this->queriesFor(1), $this->queriesFor(4));
    }

    private function queriesFor(int $equipmentCount): int
    {
        static $next = 0;
        for ($i = 0; $i < $equipmentCount; $i++) {
            $id = 'EQ-Q'.(++$next);
            $this->equipment($id, 'S-1', 'down');
            $this->pmTask($id, PmStatus::Scheduled, '2026-11-01');
            $this->report($this->pmTask($id, PmStatus::Completed, '2026-10-01', '2026-10-01'), 1, true);
            $this->eCall($id, ECallPriority::Emergency, ECallStatus::ForVendorAssessment);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->dashboard(['vendor_id' => $this->vendor->id])->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    }
}
