<?php

namespace Tests\Feature;

use App\Enums\ApprovalAction;
use App\Enums\ECallStatus;
use App\Enums\WorkOrderStatus;
use App\MasterData\Data\Employee;
use App\MasterData\Data\Equipment;
use App\MasterData\Data\Site;
use App\MasterData\InMemoryMasterDataDirectory;
use App\MasterData\MasterDataDirectory;
use App\Models\ECall;
use App\Models\SiteSetting;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * "Waiting for me" Work Order items (issue 40). Chain in the fake directory: P-REQ -> P-L1 -> P-L2 -> P-L3 -> P-L4.
 * Billing Work Orders carry a Billing Amount above the default upper threshold, so all four billing levels apply.
 */
class WaitingListWorkOrderApiTest extends TestCase
{
    use RefreshDatabase;

    private InMemoryMasterDataDirectory $directory;

    private Vendor $vendor;

    private User $requestor;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-22 10:00:00');
        $this->directory = $this->app->make(MasterDataDirectory::class);
        foreach (['P-REQ' => 'P-L1', 'P-L1' => 'P-L2', 'P-L2' => 'P-L3', 'P-L3' => 'P-L4', 'P-L4' => null] as $id => $head) {
            $this->setHead($id, $head);
        }

        $this->vendor = Vendor::factory()->create();
        SiteSetting::factory()->create(['site_id' => 'S-1', 'vendor_id' => $this->vendor->id, 'eic_employee_id' => 'P-EIC']);
        $this->requestor = User::factory()->create(['employee_id' => 'P-REQ']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function setHead(string $employeeId, ?string $headId): void
    {
        $this->directory->addEmployee(new Employee(id: $employeeId, name: "Employee {$employeeId}", immediateHeadId: $headId));
    }

    private function employee(string $employeeId): User
    {
        return User::factory()->create(['employee_id' => $employeeId]);
    }

    /**
     * @param  array<string, mixed>  $eCallAttributes
     */
    private function workOrder(WorkOrderStatus $status, array $eCallAttributes = []): WorkOrder
    {
        $eCall = ECall::factory()->create([
            'site_id' => 'S-1',
            'site_name_snapshot' => 'North Plant',
            'nature_of_issue' => 'Leaking pipe',
            'status' => ECallStatus::Closed,
            'requestor_user_id' => $this->requestor->id,
            'requestor_employee_id' => 'P-REQ',
            'vendor_id' => $this->vendor->id,
            ...$eCallAttributes,
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
            'billing_amount_millicentavos' => $billing ? 200_000_000_000 : null,
            'billing_amount_centavos' => $billing ? 200_000_000 : null,
        ]);
    }

    private function approvalRecord(WorkOrder $workOrder, User $actor, ApprovalAction $action, WorkOrderStatus $from, WorkOrderStatus $to, string $at): void
    {
        Carbon::setTestNow($at);
        $workOrder->approvalRecords()->create([
            'action' => $action,
            'actor_user_id' => $actor->id,
            'actor_employee_id' => $actor->employee_id,
            'remarks' => null,
            'previous_status' => $from->value,
            'resulting_status' => $to->value,
        ]);
        Carbon::setTestNow('2026-11-22 10:00:00');
    }

    /**
     * @return list<int>
     */
    private function workOrderIdsFor(User $user): array
    {
        $items = $this->actingAs($user)->getJson('/api/me/waiting')->assertOk()->json('data');

        return array_values(array_column(array_filter($items, fn (array $item): bool => $item['type'] === 'work_order'), 'id'));
    }

    /**
     * Everyone who might plausibly see a Work Order: the Requestor, each chain level, the EIC, the E-Call's Vendor's
     * staff, another Vendor's staff and an unrelated employee.
     *
     * @return array<string, User>
     */
    private function everyone(): array
    {
        return [
            'requestor' => $this->requestor,
            'P-L1' => $this->employee('P-L1'),
            'P-L2' => $this->employee('P-L2'),
            'P-L3' => $this->employee('P-L3'),
            'P-L4' => $this->employee('P-L4'),
            'eic' => $this->employee('P-EIC'),
            'vendor staff' => User::factory()->vendorStaff($this->vendor)->create(),
            'other vendor staff' => User::factory()->vendorStaff(Vendor::factory()->create())->create(),
            'outsider' => $this->employee('P-OUT'),
        ];
    }

    /**
     * @param  list<string>  $expected  Keys of everyone() who must see the Work Order.
     */
    private function assertSeenOnlyBy(WorkOrder $workOrder, array $expected): void
    {
        foreach ($this->everyone() as $who => $user) {
            $this->assertSame(
                in_array($who, $expected, true) ? [$workOrder->id] : [],
                $this->workOrderIdsFor($user),
                "Unexpected Work Order items for {$who}.",
            );
        }
    }

    /**
     * @return array<string, array{WorkOrderStatus, list<string>}>
     */
    public static function statusesAndActors(): array
    {
        return [
            'open: the Requestor' => [WorkOrderStatus::Open, ['requestor']],
            'lvl1 endorsement' => [WorkOrderStatus::ForLvl1Endorsement, ['P-L1']],
            'lvl2 endorsement' => [WorkOrderStatus::ForLvl2Endorsement, ['P-L2']],
            'for implementation: vendor staff' => [WorkOrderStatus::ForImplementation, ['vendor staff']],
            'implemented: the current EIC' => [WorkOrderStatus::Implemented, ['eic']],
            'billing lvl1' => [WorkOrderStatus::ForBillingEndorsementLvl1, ['P-L1']],
            'billing lvl2' => [WorkOrderStatus::ForBillingEndorsementLvl2, ['P-L2']],
            'billing lvl3' => [WorkOrderStatus::ForBillingEndorsementLvl3, ['P-L3']],
            'billing lvl4' => [WorkOrderStatus::ForBillingEndorsementLvl4, ['P-L4']],
            'closed: nobody' => [WorkOrderStatus::Closed, []],
            'rejected: nobody' => [WorkOrderStatus::Rejected, []],
            'cancelled: nobody' => [WorkOrderStatus::Cancelled, []],
        ];
    }

    /**
     * @param  list<string>  $expected
     */
    #[DataProvider('statusesAndActors')]
    public function test_each_status_waits_for_exactly_the_right_user(WorkOrderStatus $status, array $expected): void
    {
        $this->assertSeenOnlyBy($this->workOrder($status), $expected);
    }

    public function test_open_goes_to_the_creator_not_to_another_employee_with_the_same_role(): void
    {
        $workOrder = $this->workOrder(WorkOrderStatus::Open);

        $this->assertSame([$workOrder->id], $this->workOrderIdsFor($this->requestor));
        $this->assertSame([], $this->workOrderIdsFor($this->employee('P-OTHER-REQ')));
    }

    public function test_an_endorsement_item_moves_to_the_new_level_holder_when_the_chain_changes(): void
    {
        $endorsement = $this->workOrder(WorkOrderStatus::ForLvl1Endorsement);
        $billing = $this->workOrder(WorkOrderStatus::ForBillingEndorsementLvl1);
        $oldHolder = $this->employee('P-L1');

        $this->assertSame([$endorsement->id, $billing->id], $this->workOrderIdsFor($oldHolder));

        $this->setHead('P-REQ', 'P-L1B');
        $this->setHead('P-L1B', 'P-L2');

        $this->assertSame([], $this->workOrderIdsFor($oldHolder));
        $this->assertSame([$endorsement->id, $billing->id], $this->workOrderIdsFor($this->employee('P-L1B')));
    }

    public function test_an_implemented_item_moves_with_the_current_site_eic(): void
    {
        $workOrder = $this->workOrder(WorkOrderStatus::Implemented);
        SiteSetting::query()->where('site_id', 'S-1')->update(['eic_employee_id' => 'P-NEW-EIC']);

        $this->assertSame([], $this->workOrderIdsFor($this->employee('P-EIC')));
        $this->assertSame([$workOrder->id], $this->workOrderIdsFor($this->employee('P-NEW-EIC')));
    }

    public function test_a_chain_that_stops_short_shows_the_work_order_to_nobody(): void
    {
        // The chain stops at P-L1, so level 2 doesn't resolve. (A Work Order waiting at level 1 still shows to P-L1:
        // that level resolves, and only an approve onwards is blocked.)
        $this->setHead('P-L1', null);
        $endorsement = $this->workOrder(WorkOrderStatus::ForLvl2Endorsement);
        $billing = $this->workOrder(WorkOrderStatus::ForBillingEndorsementLvl2);

        foreach ($this->everyone() as $who => $user) {
            $ids = $this->workOrderIdsFor($user);
            $this->assertNotContains($endorsement->id, $ids, "{$who} sees a Work Order whose level 2 doesn't resolve.");
            $this->assertNotContains($billing->id, $ids, "{$who} sees a billing Work Order with a short chain.");
        }
    }

    public function test_a_conflicting_chain_shows_the_work_order_to_nobody(): void
    {
        // Level 1 and level 2 are the same person (a loop): separation of duties blocks the endorsement.
        $this->setHead('P-L1', 'P-L1');
        $workOrder = $this->workOrder(WorkOrderStatus::ForLvl1Endorsement);

        foreach ($this->everyone() as $who => $user) {
            $this->assertNotContains($workOrder->id, $this->workOrderIdsFor($user), "{$who} sees a Work Order with a conflicting chain.");
        }
    }

    public function test_a_requestor_without_an_employee_id_shows_endorsement_items_to_nobody(): void
    {
        $workOrder = $this->workOrder(WorkOrderStatus::ForLvl1Endorsement, ['requestor_employee_id' => null]);

        foreach ($this->everyone() as $who => $user) {
            $this->assertNotContains($workOrder->id, $this->workOrderIdsFor($user), "{$who} sees a Work Order whose Requestor has no employee ID.");
        }
    }

    public function test_a_billing_approver_who_became_the_next_level_holder_does_not_see_it(): void
    {
        $workOrder = $this->workOrder(WorkOrderStatus::ForBillingEndorsementLvl2);
        $levelOneApprover = $this->employee('P-L1');
        $eic = $this->employee('P-EIC');
        $this->approvalRecord($workOrder, $eic, ApprovalAction::Approve, WorkOrderStatus::Implemented, WorkOrderStatus::ForBillingEndorsementLvl1, '2026-11-20 09:00:00');
        $this->approvalRecord($workOrder, $levelOneApprover, ApprovalAction::Approve, WorkOrderStatus::ForBillingEndorsementLvl1, WorkOrderStatus::ForBillingEndorsementLvl2, '2026-11-21 09:00:00');

        // The org changes: P-L1 now holds level 2 of the Requestor's chain.
        $this->setHead('P-REQ', 'P-L1B');
        $this->setHead('P-L1B', 'P-L1');
        $this->setHead('P-L1', 'P-L3');
        $this->setHead('P-L3', 'P-L4');
        $this->setHead('P-L4', 'P-L5');
        $this->setHead('P-L5', null);

        $this->assertSame([], $this->workOrderIdsFor($levelOneApprover));
    }

    public function test_an_endorsement_approver_who_became_the_next_level_holder_does_not_see_it(): void
    {
        $workOrder = $this->workOrder(WorkOrderStatus::ForLvl2Endorsement);
        $levelOneApprover = $this->employee('P-L1');
        $this->approvalRecord($workOrder, $levelOneApprover, ApprovalAction::Approve, WorkOrderStatus::ForLvl1Endorsement, WorkOrderStatus::ForLvl2Endorsement, '2026-11-21 09:00:00');

        $this->setHead('P-REQ', 'P-L1B');
        $this->setHead('P-L1B', 'P-L1');

        $this->assertSame([], $this->workOrderIdsFor($levelOneApprover));
    }

    public function test_a_previous_billing_round_approval_does_not_hide_the_item(): void
    {
        // P-L2 approved level 2 in an earlier round; the round restarted from Implemented, and P-L2 now holds level 1.
        $workOrder = $this->workOrder(WorkOrderStatus::ForBillingEndorsementLvl1);
        $eic = $this->employee('P-EIC');
        $levelTwo = $this->employee('P-L2');
        $this->approvalRecord($workOrder, $eic, ApprovalAction::Approve, WorkOrderStatus::Implemented, WorkOrderStatus::ForBillingEndorsementLvl1, '2026-11-18 09:00:00');
        $this->approvalRecord($workOrder, $levelTwo, ApprovalAction::Approve, WorkOrderStatus::ForBillingEndorsementLvl2, WorkOrderStatus::ForBillingEndorsementLvl3, '2026-11-19 09:00:00');
        $this->approvalRecord($workOrder, $eic, ApprovalAction::Approve, WorkOrderStatus::Implemented, WorkOrderStatus::ForBillingEndorsementLvl1, '2026-11-20 09:00:00');
        $this->setHead('P-REQ', 'P-L2');
        $this->setHead('P-L2', 'P-L1');
        $this->setHead('P-L1', 'P-L3');

        $this->assertSame([$workOrder->id], $this->workOrderIdsFor($levelTwo));
    }

    public function test_item_shape_and_waiting_since(): void
    {
        Carbon::setTestNow('2026-11-10 08:00:00');
        $withApproval = $this->workOrder(WorkOrderStatus::ForLvl2Endorsement, ['nature_of_issue' => 'Broken pump', 'site_name_snapshot' => 'Old North Plant']);
        Carbon::setTestNow('2026-11-15 08:00:00');
        $withoutApproval = $this->workOrder(WorkOrderStatus::ForLvl2Endorsement);
        Carbon::setTestNow('2026-11-22 10:00:00');
        $this->approvalRecord($withApproval, $this->employee('P-L1'), ApprovalAction::Approve, WorkOrderStatus::ForLvl1Endorsement, WorkOrderStatus::ForLvl2Endorsement, '2026-11-12 08:00:00');
        $this->approvalRecord($withApproval, $this->employee('P-L1'), ApprovalAction::Approve, WorkOrderStatus::ForLvl1Endorsement, WorkOrderStatus::ForLvl2Endorsement, '2026-11-20 08:00:00');

        $items = $this->actingAs($this->employee('P-L2'))->getJson('/api/me/waiting')->assertOk()->json('data');

        $this->assertSame([$withoutApproval->id, $withApproval->id], array_column($items, 'id'));
        $this->assertSame('work_order', $items[1]['type']);
        $this->assertSame('Broken pump', $items[1]['title']);
        $this->assertSame(['id' => 'S-1', 'name' => 'Old North Plant'], $items[1]['site']);
        $this->assertSame('for_lvl2_endorsement', $items[1]['status']);
        $this->assertNull($items[1]['timeliness']);
        $this->assertNull($items[1]['priority']);
        $this->assertSame(Carbon::parse('2026-11-20 08:00:00')->toJSON(), Carbon::parse($items[1]['waiting_since'])->toJSON());
        $this->assertSame(Carbon::parse('2026-11-15 08:00:00')->toJSON(), Carbon::parse($items[0]['waiting_since'])->toJSON());
    }

    public function test_work_order_items_sort_together_with_e_call_items(): void
    {
        Carbon::setTestNow('2026-11-10 08:00:00');
        $older = $this->workOrder(WorkOrderStatus::Open);
        Carbon::setTestNow('2026-11-12 08:00:00');
        $eCall = ECall::factory()->create(['status' => ECallStatus::Approved, 'requestor_user_id' => $this->requestor->id, 'site_id' => 'S-1']);
        Carbon::setTestNow('2026-11-14 08:00:00');
        $newer = $this->workOrder(WorkOrderStatus::Open);
        Carbon::setTestNow('2026-11-22 10:00:00');

        $items = $this->actingAs($this->requestor)->getJson('/api/me/waiting')->assertOk()->json('data');

        $this->assertSame(
            [['work_order', $older->id], ['e_call', $eCall->id], ['work_order', $newer->id]],
            array_map(fn (array $item): array => [$item['type'], $item['id']], $items),
        );
    }

    public function test_the_chain_is_resolved_once_per_requestor_per_request(): void
    {
        foreach ([WorkOrderStatus::ForLvl1Endorsement, WorkOrderStatus::ForLvl2Endorsement, WorkOrderStatus::ForBillingEndorsementLvl1, WorkOrderStatus::ForBillingEndorsementLvl3] as $status) {
            $this->workOrder($status);
            $this->workOrder($status);
        }
        $lookups = [];
        $directory = $this->directory;
        $this->app->instance(MasterDataDirectory::class, new class($directory, $lookups) implements MasterDataDirectory
        {
            /** @param  array<string, int>  $lookups */
            public function __construct(private InMemoryMasterDataDirectory $inner, private array &$lookups) {}

            public function findEmployee(string $employeeId): ?Employee
            {
                $this->lookups[$employeeId] = ($this->lookups[$employeeId] ?? 0) + 1;

                return $this->inner->findEmployee($employeeId);
            }

            public function findSite(string $siteId): ?Site
            {
                return $this->inner->findSite($siteId);
            }

            public function listSites(): array
            {
                return $this->inner->listSites();
            }

            public function findEquipment(string $equipmentId): ?Equipment
            {
                return $this->inner->findEquipment($equipmentId);
            }

            public function listEquipmentAtSite(string $siteId): array
            {
                return $this->inner->listEquipmentAtSite($siteId);
            }

            public function listEquipmentCategories(): array
            {
                return $this->inner->listEquipmentCategories();
            }
        });

        $this->workOrderIdsFor($this->employee('P-L1'));

        $this->assertNotEmpty($lookups);
        foreach ($lookups as $employeeId => $count) {
            $this->assertSame(1, $count, "Employee {$employeeId} was looked up {$count} times in one request.");
        }
    }
}
