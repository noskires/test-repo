<?php

namespace Tests\Feature;

use App\Enums\ECallStatus;
use App\Enums\PriceListItemType;
use App\Enums\WorkOrderStatus;
use App\MasterData\Data\Employee;
use App\MasterData\InMemoryMasterDataDirectory;
use App\MasterData\MasterDataDirectory;
use App\Models\ApprovalRecord;
use App\Models\ECall;
use App\Models\PriceListItem;
use App\Models\SiteSetting;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkOrder;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Issue 38: separation of duties and no status oracle on Work Order endorsement.
 * Chain in the fake directory: P-REQ (Requestor) -> P-L1 -> P-L2 -> P-L3.
 */
class WorkOrderEndorsementHardeningTest extends TestCase
{
    use RefreshDatabase;

    private InMemoryMasterDataDirectory $directory;

    private User $requestor;

    private WorkOrder $workOrder;

    private int $boqLineId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = $this->app->make(MasterDataDirectory::class);
        $this->setHead('P-REQ', 'P-L1');
        $this->setHead('P-L1', 'P-L2');
        $this->setHead('P-L2', 'P-L3');
        $this->setHead('P-L3', null);

        $vendor = Vendor::factory()->create();
        SiteSetting::factory()->create(['site_id' => 'S-1', 'vendor_id' => $vendor->id, 'eic_employee_id' => 'P-EIC']);
        $this->requestor = self::employeeUser('P-REQ');
        $eCall = ECall::factory()->create([
            'site_id' => 'S-1',
            'vendor_id' => $vendor->id,
            'requestor_user_id' => $this->requestor->id,
            'requestor_employee_id' => 'P-REQ',
            'status' => ECallStatus::Approved,
        ]);
        $proposal = $eCall->proposals()->create([
            'revision' => 1,
            'assessment_notes' => 'Replace the gate.',
            'fsr_disk' => 'local',
            'fsr_path' => 'fsr.pdf',
            'fsr_original_name' => 'fsr.pdf',
            'fsr_mime_type' => 'application/pdf',
            'fsr_size_bytes' => 100,
            'total_millicentavos' => 10_000_000,
            'total_centavos' => 10_000,
            'submitted_by_user_id' => User::factory()->vendorStaff($vendor)->create()->id,
            'submitted_at' => now(),
        ]);
        $item = PriceListItem::factory()->create(['vendor_id' => $vendor->id, 'unit_price_centavos' => 10_000]);
        $boqLine = $proposal->lines()->create([
            'price_list_item_id' => $item->id,
            'code' => $item->code,
            'description' => 'Gate replacement',
            'type' => PriceListItemType::Service,
            'unit_of_measure' => 'lot',
            'quantity_thousandths' => 1_000,
            'unit_price_centavos' => 10_000,
            'line_total_millicentavos' => 10_000_000,
        ]);
        $this->boqLineId = $boqLine->id;
        $eCall->update(['approved_proposal_id' => $proposal->id]);

        $this->workOrder = WorkOrder::createFromECall($eCall, $this->requestor, [['line' => $boqLine, 'quantity_thousandths' => 1_000]], []);
    }

    private function setHead(string $employeeId, ?string $headId): void
    {
        $this->directory->addEmployee(new Employee(id: $employeeId, name: "Employee {$employeeId}", immediateHeadId: $headId));
    }

    private function employee(string $employeeId): User
    {
        return self::employeeUser($employeeId);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function act(string $action, array $payload = []): TestResponse
    {
        return $this->postJson("/api/work-orders/{$this->workOrder->id}/{$action}", $payload);
    }

    private function currentStatus(): WorkOrderStatus
    {
        return $this->workOrder->refresh()->status;
    }

    private function submitAsRequestor(): void
    {
        $this->actingAs($this->requestor);
        $this->act('submit')->assertOk();
    }

    private function approveAs(string $employeeId): void
    {
        $this->actingAs($this->employee($employeeId));
        $this->act('approve')->assertOk();
    }

    /**
     * @return array<string, array{Closure(self): void, string}>
     */
    public static function conflictingChains(): array
    {
        return [
            'level 1 head is also the level 2 head' => [
                fn (self $test) => $test->setHead('P-L1', 'P-L1'),
                'Level 1 and level 2 resolve to the same person (P-L1). Separation of duties requires two different endorsers.',
            ],
            'level 1 head is the requestor' => [
                fn (self $test) => $test->setHead('P-REQ', 'P-REQ'),
                'The level 1 endorser would be the Requestor (P-REQ). Separation of duties requires someone else.',
            ],
            'level 2 head is the requestor' => [
                fn (self $test) => $test->setHead('P-L1', 'P-REQ'),
                'The level 2 endorser would be the Requestor (P-REQ). Separation of duties requires someone else.',
            ],
        ];
    }

    /**
     * @param  Closure(self): void  $makeConflict
     */
    #[DataProvider('conflictingChains')]
    public function test_submit_refuses_a_conflicting_chain(Closure $makeConflict, string $message): void
    {
        $makeConflict($this);
        $this->actingAs($this->requestor);

        $this->act('submit')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['work_order' => $message]);

        $this->assertSame(WorkOrderStatus::Open, $this->currentStatus());
        $this->assertSame(0, ApprovalRecord::query()->count());
    }

    /**
     * @param  Closure(self): void  $makeConflict
     */
    #[DataProvider('conflictingChains')]
    public function test_every_endorse_action_refuses_a_chain_that_became_conflicting_while_waiting(Closure $makeConflict, string $message): void
    {
        $this->submitAsRequestor();
        $makeConflict($this);

        foreach (['P-L1', 'P-REQ', 'P-OTHER'] as $employeeId) {
            $this->actingAs($this->employee($employeeId));

            foreach (['approve' => [], 'return' => ['remarks' => 'x'], 'reject' => ['remarks' => 'x']] as $action => $payload) {
                $this->act($action, $payload)
                    ->assertUnprocessable()
                    ->assertJsonValidationErrors(['work_order' => $message]);
            }
        }

        $this->assertSame(WorkOrderStatus::ForLvl1Endorsement, $this->currentStatus());
        $this->assertSame(0, ApprovalRecord::query()->count());
    }

    public function test_a_level_1_approver_who_becomes_the_level_2_head_cannot_approve_level_2_but_may_return(): void
    {
        $this->submitAsRequestor();
        $levelOneApprover = $this->employee('P-L1');
        $this->actingAs($levelOneApprover);
        $this->act('approve')->assertOk();

        // The org chart changes mid-cycle: P-L1 is now the level 2 head (P-REQ -> P-L1B -> P-L1).
        $this->setHead('P-REQ', 'P-L1B');
        $this->setHead('P-L1B', 'P-L1');
        $this->setHead('P-L1', null);

        $this->act('approve')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['work_order' => 'You already approved this Work Order at level 1 in the current endorsement round. Separation of duties requires a different endorser at each level.']);

        $this->assertSame(WorkOrderStatus::ForLvl2Endorsement, $this->currentStatus());
        $this->assertSame(1, ApprovalRecord::query()->count());

        // Only approving a second level is refused: the same person may still send it back one step.
        $this->act('return', ['remarks' => 'Over to the new team head.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'for_lvl1_endorsement');
    }

    public function test_a_level_1_approver_who_becomes_the_level_2_head_may_still_reject(): void
    {
        $this->submitAsRequestor();
        $this->approveAs('P-L1');
        $this->setHead('P-REQ', 'P-L1B');
        $this->setHead('P-L1B', 'P-L1');
        $this->setHead('P-L1', null);

        $this->actingAs($this->employee('P-L1'));
        $this->act('reject', ['remarks' => 'Not justified after all.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected');
    }

    public function test_the_double_approval_check_also_matches_by_employee_id(): void
    {
        $this->submitAsRequestor();
        $this->approveAs('P-L1');

        $this->setHead('P-REQ', 'P-L1B');
        $this->setHead('P-L1B', 'P-L1');
        $this->setHead('P-L1', null);

        $this->actingAs($this->employee('P-L1'));
        $this->act('approve')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['work_order']);

        $this->assertSame(WorkOrderStatus::ForLvl2Endorsement, $this->currentStatus());
    }

    public function test_a_resubmit_after_a_level_1_return_starts_a_new_cycle(): void
    {
        $this->submitAsRequestor();
        $this->approveAs('P-L1');
        $this->actingAs($this->employee('P-L2'));
        $this->act('return', ['remarks' => 'Back to level 1.'])->assertOk();
        $this->actingAs($this->employee('P-L1'));
        $this->act('return', ['remarks' => 'Back to you.'])->assertOk()->assertJsonPath('data.status', 'open');

        $this->submitAsRequestor();

        $this->approveAs('P-L1');
        $this->assertSame(WorkOrderStatus::ForLvl2Endorsement, $this->currentStatus());
    }

    public function test_an_earlier_cycles_approver_who_is_now_the_level_2_head_may_act_in_a_new_cycle(): void
    {
        $this->submitAsRequestor();
        $this->approveAs('P-L1');
        $this->actingAs($this->employee('P-L2'));
        $this->act('return', ['remarks' => 'x'])->assertOk();
        $this->actingAs($this->employee('P-L1'));
        $this->act('return', ['remarks' => 'x'])->assertOk();

        $this->setHead('P-REQ', 'P-L1B');
        $this->setHead('P-L1B', 'P-L1');
        $this->setHead('P-L1', null);
        $this->submitAsRequestor();
        $this->approveAs('P-L1B');

        $this->approveAs('P-L1');
        $this->assertSame(WorkOrderStatus::ForImplementation, $this->currentStatus());
    }

    public function test_after_a_level_2_return_the_level_1_head_may_approve_level_1_again(): void
    {
        $this->submitAsRequestor();
        $this->approveAs('P-L1');
        $this->actingAs($this->employee('P-L2'));
        $this->act('return', ['remarks' => 'Re-check.'])->assertOk()->assertJsonPath('data.status', 'for_lvl1_endorsement');

        // Re-approving the SAME level in the same round is allowed, and the flow continues normally.
        $this->actingAs($this->employee('P-L1'));
        $this->act('approve')->assertOk()->assertJsonPath('data.status', 'for_lvl2_endorsement');
        $this->approveAs('P-L2');
        $this->assertSame(WorkOrderStatus::ForImplementation, $this->currentStatus());
    }

    public function test_a_loop_above_level_2_does_not_block_submit(): void
    {
        $this->setHead('P-L1', 'P-L2');
        $this->setHead('P-L2', 'P-L1');
        $this->setHead('P-REQ', 'P-L1');
        $this->actingAs($this->requestor);

        $this->act('submit')->assertOk();
    }

    /**
     * @return array<string, array{Closure(self): void}>
     */
    public static function workOrderStatesWorthHiding(): array
    {
        return [
            'broken chain while pending' => [function (self $test): void {
                $test->submitAsRequestor();
                $test->setHead('P-REQ', null);
            }],
            'not pending endorsement' => [fn (self $test) => null],
        ];
    }

    /**
     * @param  Closure(self): void  $arrange
     */
    #[DataProvider('workOrderStatesWorthHiding')]
    public function test_a_user_who_cannot_view_the_work_order_gets_a_plain_403(Closure $arrange): void
    {
        $arrange($this);
        $this->actingAs(self::vendorStaffWithEmployeeId('P-L1'));

        $this->act('submit')->assertForbidden();
        $this->act('approve')->assertForbidden();
        $this->act('return', ['remarks' => 'x'])->assertForbidden();
        $this->act('reject', ['remarks' => 'x'])->assertForbidden();
        $this->getJson("/api/work-orders/{$this->workOrder->id}/approvals")->assertForbidden();
    }

    /**
     * @param  Closure(self): void  $arrange
     */
    #[DataProvider('workOrderStatesWorthHiding')]
    public function test_a_user_who_can_view_the_work_order_still_gets_the_422_details(Closure $arrange): void
    {
        $arrange($this);
        $this->actingAs($this->employee('P-OTHER'));

        $this->act('approve')->assertUnprocessable()->assertJsonValidationErrors(['work_order']);
    }

    public function test_a_viewer_who_is_not_the_creator_still_gets_403_on_submit(): void
    {
        $this->actingAs($this->employee('P-OTHER'));

        $this->act('submit')->assertForbidden();
    }

    public function test_the_endorsement_round_starts_at_the_latest_submit(): void
    {
        $this->submitAsRequestor();
        $firstFloor = DB::table('work_orders')->where('id', $this->workOrder->id)->value('endorsement_round_after_record_id');
        $this->approveAs('P-L1');
        $this->actingAs($this->employee('P-L2'));
        $this->act('return', ['remarks' => 'x'])->assertOk();
        $this->actingAs($this->employee('P-L1'));
        $this->act('return', ['remarks' => 'x'])->assertOk();

        $this->submitAsRequestor();

        $this->assertSame(0, (int) $firstFloor);
        $this->assertSame(
            (int) ApprovalRecord::query()->max('id'),
            (int) DB::table('work_orders')->where('id', $this->workOrder->id)->value('endorsement_round_after_record_id'),
        );
        $this->assertNotNull($this->workOrder->refresh()->submitted_at);
    }
}
