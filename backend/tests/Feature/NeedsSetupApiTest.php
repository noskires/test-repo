<?php

namespace Tests\Feature;

use App\Enums\ECallStatus;
use App\Enums\PmFrequency;
use App\Enums\PmStatus;
use App\Enums\WorkOrderStatus;
use App\Jobs\GeneratePmTasks;
use App\MasterData\Data\Employee;
use App\MasterData\Data\Equipment;
use App\MasterData\Data\Site;
use App\MasterData\InMemoryMasterDataDirectory;
use App\MasterData\MasterDataDirectory;
use App\Models\ECall;
use App\Models\PmTask;
use App\Models\SiteSetting;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkOrder;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The admin Needs Setup list (issue 32), computed live from the directory fake and PSS's own records.
 * Work Order chain in the fake directory: P-REQ -> P-L1 -> P-L2 -> P-L3 -> P-L4.
 */
class NeedsSetupApiTest extends TestCase
{
    use RefreshDatabase;

    private InMemoryMasterDataDirectory $directory;

    private Vendor $vendor;

    private User $requestor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = $this->app->make(MasterDataDirectory::class);
        $this->directory
            ->addSite(new Site(id: 'S-1', name: 'Makati Office'))
            ->addSite(new Site(id: 'S-2', name: 'Cebu Site'))
            ->addSite(new Site(id: 'S-3', name: 'Davao Site'));
        foreach (['P-REQ' => 'P-L1', 'P-L1' => 'P-L2', 'P-L2' => 'P-L3', 'P-L3' => 'P-L4', 'P-L4' => null] as $id => $head) {
            $this->setHead($id, $head);
        }

        $this->vendor = Vendor::factory()->create();
        $this->requestor = User::factory()->create(['employee_id' => 'P-REQ', 'name' => 'Rhea Requestor']);
    }

    private function actingAsAdmin(bool $isAllowed = true): void
    {
        Gate::define('manage-site-settings', fn (User $user): bool => $isAllowed);
        $this->actingAs(User::factory()->create());
    }

    private function setHead(string $employeeId, ?string $headId): void
    {
        $this->directory->addEmployee(new Employee(id: $employeeId, name: "Employee {$employeeId}", immediateHeadId: $headId));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function settings(string $siteId, array $attributes = []): SiteSetting
    {
        return SiteSetting::factory()->create([
            'site_id' => $siteId,
            'vendor_id' => $this->vendor->id,
            'eic_employee_id' => 'P-EIC',
            'pm_frequency' => PmFrequency::Monthly,
            ...$attributes,
        ]);
    }

    private function equipment(string $id, ?string $installedOn, string $siteId = 'S-1'): Equipment
    {
        return new Equipment(
            id: $id,
            siteId: $siteId,
            name: "Unit {$id}",
            categoryId: 'CAT-AC',
            installedOn: $installedOn === null ? null : new DateTimeImmutable($installedOn),
        );
    }

    private function workOrder(WorkOrderStatus $status, ?string $requestorEmployeeId = 'P-REQ', int $billingAmountCentavos = 200_000_000): WorkOrder
    {
        $eCall = ECall::factory()->create([
            'site_id' => 'S-1',
            'site_name_snapshot' => 'Makati Office',
            'nature_of_issue' => 'Leaking pipe',
            'status' => ECallStatus::Closed,
            'requestor_user_id' => $this->requestor->id,
            'requestor_employee_id' => $requestorEmployeeId,
            'vendor_id' => $this->vendor->id,
        ]);
        $proposal = $eCall->proposals()->create([
            'revision' => 1,
            'assessment_notes' => 'Assessment.',
            'fsr_disk' => 'local',
            'fsr_path' => 'fsr.pdf',
            'fsr_original_name' => 'fsr.pdf',
            'fsr_mime_type' => 'application/pdf',
            'fsr_size_bytes' => 100,
            'total_millicentavos' => 0,
            'total_centavos' => 0,
            'submitted_by_user_id' => User::factory()->vendorStaff($this->vendor)->create()->id,
            'submitted_at' => now(),
        ]);
        $billing = $status->billingLevel() !== null;

        return WorkOrder::query()->create([
            'e_call_id' => $eCall->id,
            'e_call_proposal_id' => $proposal->id,
            'status' => $status,
            'created_by_user_id' => $eCall->requestor_user_id,
            'estimated_amount_millicentavos' => 0,
            'estimated_amount_centavos' => 0,
            'billing_amount_millicentavos' => $billing ? $billingAmountCentavos * 1000 : null,
            'billing_amount_centavos' => $billing ? $billingAmountCentavos : null,
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function entries(): array
    {
        return $this->getJson('/api/needs-setup')->assertOk()->json('data');
    }

    /**
     * @return array<string, list<string>> reasons keyed by "type:id"
     */
    private function reasons(): array
    {
        $reasons = [];

        foreach ($this->entries() as $entry) {
            $reasons["{$entry['type']}:{$entry['id']}"] = $entry['reasons'];
        }

        return $reasons;
    }

    public function test_a_site_missing_its_vendor_or_eic_is_listed_with_each_missing_reason(): void
    {
        $this->actingAsAdmin();
        $this->settings('S-1', ['vendor_id' => null]);
        $this->settings('S-2', ['eic_employee_id' => null]);
        $this->settings('S-3', ['vendor_id' => null, 'eic_employee_id' => null]);

        $this->assertSame([
            'site:S-1' => ['no_site_vendor'],
            'site:S-2' => ['no_eic'],
            'site:S-3' => ['no_site_vendor', 'no_eic'],
        ], $this->reasons());

        $this->assertSame(['type' => 'site', 'id' => 'S-1', 'name' => 'Makati Office', 'reasons' => ['no_site_vendor']], $this->entries()[0]);
    }

    public function test_a_fully_set_up_site_is_not_listed(): void
    {
        $this->actingAsAdmin();
        $this->settings('S-1');

        $this->assertSame([], $this->entries());
    }

    public function test_a_site_without_a_pm_frequency_and_its_equipment_are_not_listed(): void
    {
        $this->actingAsAdmin();
        $this->settings('S-1', ['pm_frequency' => null, 'vendor_id' => null, 'eic_employee_id' => null]);
        $this->directory->addEquipment($this->equipment('EQ-1', installedOn: null));

        $this->assertSame([], $this->entries());
    }

    public function test_equipment_without_installation_date_or_pm_history_is_listed_until_the_directory_gives_one(): void
    {
        $this->actingAsAdmin();
        $this->settings('S-1');
        $this->directory
            ->addEquipment($this->equipment('EQ-1', installedOn: null))
            ->addEquipment($this->equipment('EQ-2', installedOn: '2026-01-15'));

        $this->assertSame(['equipment:EQ-1' => ['no_start_date']], $this->reasons());
        $this->assertSame([
            'type' => 'equipment',
            'id' => 'EQ-1',
            'name' => 'Unit EQ-1',
            'site' => ['id' => 'S-1', 'name' => 'Makati Office'],
            'reasons' => ['no_start_date'],
        ], $this->entries()[0]);

        $this->directory->addEquipment($this->equipment('EQ-1', installedOn: '2026-02-01'));

        $this->assertSame([], $this->entries());
    }

    public function test_equipment_with_pm_history_is_not_listed(): void
    {
        $this->actingAsAdmin();
        $this->settings('S-1');
        $this->directory
            ->addEquipment($this->equipment('EQ-DONE', installedOn: null))
            ->addEquipment($this->equipment('EQ-OPEN', installedOn: null));
        PmTask::factory()->completed(CarbonImmutable::parse('2026-03-31'))->create(['site_id' => 'S-1', 'equipment_id' => 'EQ-DONE', 'vendor_id' => $this->vendor->id]);
        PmTask::factory()->create(['site_id' => 'S-1', 'equipment_id' => 'EQ-OPEN', 'vendor_id' => $this->vendor->id, 'status' => PmStatus::Scheduled]);

        $this->assertSame([], $this->entries());
    }

    public function test_equipment_is_listed_exactly_when_the_pm_job_skips_it_for_lack_of_a_start_date(): void
    {
        $this->actingAsAdmin();
        $this->settings('S-1');
        $this->directory
            ->addEquipment($this->equipment('EQ-NONE', installedOn: null))
            ->addEquipment($this->equipment('EQ-INSTALLED', installedOn: '2026-01-15'))
            ->addEquipment($this->equipment('EQ-DONE', installedOn: null));
        PmTask::factory()->completed(CarbonImmutable::parse('2026-03-31'))->create(['site_id' => 'S-1', 'equipment_id' => 'EQ-DONE', 'vendor_id' => $this->vendor->id]);

        $listed = array_keys($this->reasons());
        GeneratePmTasks::dispatch();

        $this->assertSame(['equipment:EQ-NONE'], $listed);
        $this->assertFalse(PmTask::query()->open()->where('equipment_id', 'EQ-NONE')->exists());
        $this->assertTrue(PmTask::query()->open()->where('equipment_id', 'EQ-INSTALLED')->exists());
        $this->assertTrue(PmTask::query()->open()->where('equipment_id', 'EQ-DONE')->exists());
        $this->assertSame(['equipment:EQ-NONE' => ['no_start_date']], $this->reasons());
    }

    public function test_equipment_at_a_site_missing_its_vendor_still_gets_its_own_entry(): void
    {
        $this->actingAsAdmin();
        $this->settings('S-1', ['vendor_id' => null]);
        $this->directory->addEquipment($this->equipment('EQ-1', installedOn: null));

        $this->assertSame([
            'site:S-1' => ['no_site_vendor'],
            'equipment:EQ-1' => ['no_start_date'],
        ], $this->reasons());
    }

    public function test_a_work_order_whose_requestor_has_no_level_2_head_is_listed_until_the_chain_is_fixed(): void
    {
        $this->actingAsAdmin();
        $this->setHead('P-L1', null);
        $workOrder = $this->workOrder(WorkOrderStatus::ForLvl1Endorsement);

        $this->assertSame(["work_order:{$workOrder->id}" => ['missing_endorser_level_2']], $this->reasons());
        $this->assertSame([
            'type' => 'work_order',
            'id' => $workOrder->id,
            'status' => 'for_lvl1_endorsement',
            'e_call' => ['id' => $workOrder->e_call_id, 'nature_of_issue' => 'Leaking pipe'],
            'site' => ['id' => 'S-1', 'name' => 'Makati Office'],
            'requestor' => ['employee_id' => 'P-REQ', 'name' => 'Rhea Requestor'],
            'reasons' => ['missing_endorser_level_2'],
        ], $this->entries()[0]);

        $this->setHead('P-L1', 'P-L2');

        $this->assertSame([], $this->entries());
    }

    public function test_open_and_level_1_work_orders_check_both_levels_and_level_2_checks_only_level_2(): void
    {
        $this->actingAsAdmin();
        $this->setHead('P-REQ', null);
        $open = $this->workOrder(WorkOrderStatus::Open);
        $levelOne = $this->workOrder(WorkOrderStatus::ForLvl1Endorsement);
        $levelTwo = $this->workOrder(WorkOrderStatus::ForLvl2Endorsement);

        $this->assertSame([
            "work_order:{$open->id}" => ['missing_endorser_level_1', 'missing_endorser_level_2'],
            "work_order:{$levelOne->id}" => ['missing_endorser_level_1', 'missing_endorser_level_2'],
            "work_order:{$levelTwo->id}" => ['missing_endorser_level_2'],
        ], $this->reasons());
    }

    public function test_a_level_2_work_order_whose_chain_resolves_is_not_listed(): void
    {
        $this->actingAsAdmin();
        $this->workOrder(WorkOrderStatus::ForLvl2Endorsement);

        $this->assertSame([], $this->entries());
    }

    public function test_a_work_order_whose_requestor_has_no_employee_id_is_listed(): void
    {
        $this->actingAsAdmin();
        $workOrder = $this->workOrder(WorkOrderStatus::Open, requestorEmployeeId: null);

        $this->assertSame(["work_order:{$workOrder->id}" => ['no_requestor_employee_id']], $this->reasons());
    }

    public function test_a_conflicting_endorsement_chain_is_listed_as_a_conflict(): void
    {
        $this->actingAsAdmin();
        $this->setHead('P-L1', 'P-REQ');
        $workOrder = $this->workOrder(WorkOrderStatus::ForLvl1Endorsement);

        $this->assertSame(["work_order:{$workOrder->id}" => ['missing_endorser_level_2', 'endorsement_chain_conflict']], $this->reasons());
    }

    /**
     * @return array<string, array{WorkOrderStatus}>
     */
    public static function statusesNeverListed(): array
    {
        return [
            'for implementation' => [WorkOrderStatus::ForImplementation],
            'rejected' => [WorkOrderStatus::Rejected],
            'closed' => [WorkOrderStatus::Closed],
        ];
    }

    #[DataProvider('statusesNeverListed')]
    public function test_work_orders_past_endorsement_and_not_in_billing_are_never_listed(WorkOrderStatus $status): void
    {
        $this->actingAsAdmin();
        $this->setHead('P-REQ', null);
        $this->workOrder($status);

        $this->assertSame([], $this->entries());
    }

    /**
     * @return array<string, array{WorkOrderStatus, int, string}>
     */
    public static function billingGaps(): array
    {
        return [
            'implemented, level 1 missing' => [WorkOrderStatus::Implemented, 0, 'missing_billing_endorser_level_1'],
            'implemented, level 2 missing (every amount needs 2)' => [WorkOrderStatus::Implemented, 1, 'missing_billing_endorser_level_2'],
            'billing LVL1, level 2 missing' => [WorkOrderStatus::ForBillingEndorsementLvl1, 1, 'missing_billing_endorser_level_2'],
            'billing LVL2, level 4 missing' => [WorkOrderStatus::ForBillingEndorsementLvl2, 3, 'missing_billing_endorser_level_4'],
            'billing LVL4, level 4 missing' => [WorkOrderStatus::ForBillingEndorsementLvl4, 3, 'missing_billing_endorser_level_4'],
        ];
    }

    /**
     * $levelsThatResolve cuts the chain after that many levels.
     */
    #[DataProvider('billingGaps')]
    public function test_a_billing_work_order_with_a_missing_required_billing_level_is_listed_until_the_chain_is_fixed(WorkOrderStatus $status, int $levelsThatResolve, string $reason): void
    {
        $this->actingAsAdmin();
        $chain = ['P-REQ', 'P-L1', 'P-L2', 'P-L3', 'P-L4'];
        $this->setHead($chain[$levelsThatResolve], null);
        $workOrder = $this->workOrder($status);

        $this->assertSame(["work_order:{$workOrder->id}" => [$reason]], $this->reasons());

        $this->setHead($chain[$levelsThatResolve], $chain[$levelsThatResolve + 1]);

        $this->assertSame([], $this->entries());
    }

    public function test_billing_levels_above_those_the_amount_needs_are_not_checked(): void
    {
        $this->actingAsAdmin();
        $this->setHead('P-L2', null);
        $this->workOrder(WorkOrderStatus::ForBillingEndorsementLvl1, billingAmountCentavos: 100);

        $this->assertSame([], $this->entries(), 'A small amount needs only billing levels 1 and 2');
    }

    public function test_billing_levels_below_the_current_one_are_not_checked(): void
    {
        $this->actingAsAdmin();
        $this->setHead('P-L1', null);
        $this->workOrder(WorkOrderStatus::ForBillingEndorsementLvl3);

        $this->assertSame(1, count($this->entries()), 'Level 3 is missing, so it is listed');
        $this->assertSame(['missing_billing_endorser_level_3'], $this->entries()[0]['reasons']);
    }

    public function test_a_billing_work_order_whose_requestor_has_no_employee_id_is_listed(): void
    {
        $this->actingAsAdmin();
        $workOrder = $this->workOrder(WorkOrderStatus::ForBillingEndorsementLvl1, requestorEmployeeId: null);

        $this->assertSame(["work_order:{$workOrder->id}" => ['no_requestor_employee_id']], $this->reasons());
    }

    public function test_entries_are_ordered_sites_then_equipment_then_work_orders_each_by_id(): void
    {
        $this->actingAsAdmin();
        $this->settings('S-2', ['vendor_id' => null]);
        $this->settings('S-1', ['eic_employee_id' => null]);
        $this->directory
            ->addEquipment($this->equipment('EQ-2', installedOn: null))
            ->addEquipment($this->equipment('EQ-1', installedOn: null, siteId: 'S-2'));
        $this->setHead('P-REQ', null);
        $first = $this->workOrder(WorkOrderStatus::Open);
        $second = $this->workOrder(WorkOrderStatus::ForLvl1Endorsement);

        $this->assertSame(
            ['site:S-1', 'site:S-2', 'equipment:EQ-1', 'equipment:EQ-2', "work_order:{$first->id}", "work_order:{$second->id}"],
            array_keys($this->reasons()),
        );
    }

    public function test_returns_403_when_the_gate_denies(): void
    {
        $this->actingAsAdmin(isAllowed: false);

        $this->getJson('/api/needs-setup')->assertForbidden();
    }

    public function test_returns_401_when_unauthenticated(): void
    {
        $this->getJson('/api/needs-setup')->assertUnauthorized();
    }
}
