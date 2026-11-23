<?php

namespace Tests\Feature;

use App\Enums\ApprovalAction;
use App\Enums\ECallStatus;
use App\Enums\PriceListItemType;
use App\Enums\WorkOrderStatus;
use App\MasterData\Data\Employee;
use App\MasterData\InMemoryMasterDataDirectory;
use App\MasterData\MasterDataDirectory;
use App\Models\ApprovalRecord;
use App\Models\ECall;
use App\Models\ECallProposalLine;
use App\Models\PriceListItem;
use App\Models\SiteSetting;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkOrder;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Work Order endorsement (issue 19): the E-Call Requestor's Immediate Head chain, resolved current at each step.
 * Chain in the fake directory: P-REQ (Requestor) -> P-L1 (level 1) -> P-L2 (level 2).
 */
class WorkOrderEndorsementApiTest extends TestCase
{
    use RefreshDatabase;

    private InMemoryMasterDataDirectory $directory;

    private Vendor $siteVendor;

    private User $requestor;

    private ECall $eCall;

    private ECallProposalLine $boqLine;

    private WorkOrder $workOrder;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-23 09:00:00');

        $this->directory = $this->app->make(MasterDataDirectory::class);
        $this->directory
            ->addEmployee(new Employee(id: 'P-REQ', name: 'Rhea Requestor', immediateHeadId: 'P-L1'))
            ->addEmployee(new Employee(id: 'P-L1', name: 'Lito Team Head', immediateHeadId: 'P-L2'))
            ->addEmployee(new Employee(id: 'P-L2', name: 'Dina Division Head', immediateHeadId: 'P-L3'))
            ->addEmployee(new Employee(id: 'P-L3', name: 'Cora Center Head'));

        $this->siteVendor = Vendor::factory()->create();
        SiteSetting::factory()->create(['site_id' => 'S-1', 'vendor_id' => $this->siteVendor->id, 'eic_employee_id' => 'P-EIC']);
        $this->requestor = User::factory()->create(['employee_id' => 'P-REQ']);
        $this->eCall = ECall::factory()->create([
            'site_id' => 'S-1',
            'vendor_id' => $this->siteVendor->id,
            'requestor_user_id' => $this->requestor->id,
            'requestor_employee_id' => 'P-REQ',
            'eic_employee_id' => 'P-EIC',
            'status' => ECallStatus::Approved,
        ]);

        $proposal = $this->eCall->proposals()->create([
            'revision' => 1,
            'assessment_notes' => 'Replace the gate.',
            'fsr_disk' => 'local',
            'fsr_path' => 'fsr.pdf',
            'fsr_original_name' => 'fsr.pdf',
            'fsr_mime_type' => 'application/pdf',
            'fsr_size_bytes' => 100,
            'total_millicentavos' => 1_000 * 10_000,
            'total_centavos' => 10_000,
            'submitted_by_user_id' => User::factory()->vendorStaff($this->siteVendor)->create()->id,
            'submitted_at' => now(),
        ]);
        $item = PriceListItem::factory()->create(['vendor_id' => $this->siteVendor->id, 'unit_price_centavos' => 10_000]);
        $this->boqLine = $proposal->lines()->create([
            'price_list_item_id' => $item->id,
            'code' => $item->code,
            'description' => 'Gate replacement',
            'type' => PriceListItemType::Service,
            'unit_of_measure' => 'lot',
            'quantity_thousandths' => 1_000,
            'unit_price_centavos' => 10_000,
            'line_total_millicentavos' => 10_000_000,
        ]);
        $this->eCall->update(['approved_proposal_id' => $proposal->id]);

        $this->workOrder = WorkOrder::createFromECall($this->eCall, $this->requestor, [['line' => $this->boqLine, 'quantity_thousandths' => 1_000]], []);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function userWithEmployeeId(string $employeeId): User
    {
        return User::factory()->create(['employee_id' => $employeeId]);
    }

    private function submitAsRequestor(): void
    {
        $this->actingAs($this->requestor);
        $this->postJson("/api/work-orders/{$this->workOrder->id}/submit")->assertOk();
    }

    private function moveToLevel2(): void
    {
        $this->submitAsRequestor();
        $this->actingAs($this->userWithEmployeeId('P-L1'));
        $this->postJson("/api/work-orders/{$this->workOrder->id}/approve")->assertOk();
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

    public function test_the_creator_submits_an_open_work_order_for_level_1_endorsement_without_a_record(): void
    {
        $this->actingAs($this->requestor);

        $this->act('submit')
            ->assertOk()
            ->assertJsonPath('data.status', 'for_lvl1_endorsement');

        $this->assertSame(WorkOrderStatus::ForLvl1Endorsement, $this->currentStatus());
        $this->assertSame(0, ApprovalRecord::query()->count(), 'Submitting is not an approval action.');
    }

    /**
     * @return array<string, array{Closure(): User}>
     */
    public static function usersWhoAreNotTheCreator(): array
    {
        return [
            'the level 1 head' => [fn (): User => User::factory()->create(['employee_id' => 'P-L1'])],
            'the site eic' => [fn (): User => User::factory()->create(['employee_id' => 'P-EIC'])],
            'site vendor staff' => [fn (): User => User::factory()->vendorStaff(Vendor::query()->orderBy('id')->firstOrFail())->create()],
            'another account with the requestors employee id' => [fn (): User => User::factory()->create(['employee_id' => 'P-REQ'])],
        ];
    }

    /**
     * @param  Closure(): User  $makeUser
     */
    #[DataProvider('usersWhoAreNotTheCreator')]
    public function test_only_the_creator_can_submit(Closure $makeUser): void
    {
        $this->actingAs($makeUser());

        $this->act('submit')->assertForbidden();

        $this->assertSame(WorkOrderStatus::Open, $this->currentStatus());
    }

    public function test_submitting_when_not_open_returns_422(): void
    {
        $this->submitAsRequestor();

        $this->act('submit')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['work_order']);

        $this->assertSame(WorkOrderStatus::ForLvl1Endorsement, $this->currentStatus());
    }

    /**
     * @return array<string, array{Closure(InMemoryMasterDataDirectory, ECall): void, string}>
     */
    public static function brokenChainsAtSubmit(): array
    {
        return [
            'requestor has no employee id' => [
                fn (InMemoryMasterDataDirectory $directory, ECall $eCall) => $eCall->update(['requestor_employee_id' => null]),
                'The Requestor has no employee ID, so the level 1 endorser cannot be found.',
            ],
            'requestor unknown to the directory' => [
                fn (InMemoryMasterDataDirectory $directory, ECall $eCall) => $eCall->update(['requestor_employee_id' => 'P-GONE']),
                "No level 1 endorser: the Requestor's Immediate Head chain stops before level 1.",
            ],
            'requestor has no immediate head' => [
                fn (InMemoryMasterDataDirectory $directory) => $directory->addEmployee(new Employee(id: 'P-REQ', name: 'Rhea Requestor')),
                "No level 1 endorser: the Requestor's Immediate Head chain stops before level 1.",
            ],
            'level 1 head has no immediate head' => [
                fn (InMemoryMasterDataDirectory $directory) => $directory->addEmployee(new Employee(id: 'P-L1', name: 'Lito Team Head')),
                "No level 2 endorser: the Requestor's Immediate Head chain stops before level 2.",
            ],
        ];
    }

    /**
     * @param  Closure(InMemoryMasterDataDirectory, ECall): void  $breakChain
     */
    #[DataProvider('brokenChainsAtSubmit')]
    public function test_submit_with_a_short_or_missing_chain_returns_422_naming_the_level(Closure $breakChain, string $message): void
    {
        $breakChain($this->directory, $this->eCall);
        $this->actingAs($this->requestor);

        $this->act('submit')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['work_order' => $message]);

        $this->assertSame(WorkOrderStatus::Open, $this->currentStatus());
        $this->assertSame(0, ApprovalRecord::query()->count());
    }

    public function test_level_1_approve_moves_to_level_2_and_writes_a_record(): void
    {
        $this->submitAsRequestor();
        $levelOneHead = $this->userWithEmployeeId('P-L1');
        $this->actingAs($levelOneHead);

        $this->act('approve', ['remarks' => 'Fine.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'for_lvl2_endorsement');

        $record = ApprovalRecord::query()->sole();
        $this->assertSame('work_order', $record->subject_type);
        $this->assertSame($this->workOrder->id, $record->subject_id);
        $this->assertSame(ApprovalAction::Approve, $record->action);
        $this->assertSame($levelOneHead->id, $record->actor_user_id);
        $this->assertSame('P-L1', $record->actor_employee_id);
        $this->assertSame('Fine.', $record->remarks);
        $this->assertSame('for_lvl1_endorsement', $record->previous_status);
        $this->assertSame('for_lvl2_endorsement', $record->resulting_status);
    }

    public function test_level_2_approve_moves_to_for_implementation(): void
    {
        $this->moveToLevel2();
        $this->actingAs($this->userWithEmployeeId('P-L2'));

        $this->act('approve')
            ->assertOk()
            ->assertJsonPath('data.status', 'for_implementation');

        $record = ApprovalRecord::query()->orderByDesc('id')->firstOrFail();
        $this->assertSame('for_lvl2_endorsement', $record->previous_status);
        $this->assertSame('for_implementation', $record->resulting_status);
        $this->assertNull($record->remarks);
        $this->assertSame(2, ApprovalRecord::query()->count());
    }

    /**
     * @return array<string, array{Closure(): User}>
     */
    public static function usersWhoAreNotTheLevel1Endorser(): array
    {
        return [
            'the creator' => [fn (): User => User::query()->where('employee_id', 'P-REQ')->firstOrFail()],
            'the level 2 head' => [fn (): User => User::factory()->create(['employee_id' => 'P-L2'])],
            'the site eic' => [fn (): User => User::factory()->create(['employee_id' => 'P-EIC'])],
            'vendor staff with the level 1 heads employee id' => [fn (): User => User::factory()->vendorStaff(Vendor::query()->orderBy('id')->firstOrFail())->create(['employee_id' => 'P-L1'])],
            'a user with no employee id' => [fn (): User => User::factory()->create()],
        ];
    }

    /**
     * @param  Closure(): User  $makeUser
     */
    #[DataProvider('usersWhoAreNotTheLevel1Endorser')]
    public function test_only_the_current_level_endorser_can_act(Closure $makeUser): void
    {
        $this->submitAsRequestor();
        $this->actingAs($makeUser());

        $this->act('approve')->assertForbidden();
        $this->act('return', ['remarks' => 'x'])->assertForbidden();
        $this->act('reject', ['remarks' => 'x'])->assertForbidden();

        $this->assertSame(WorkOrderStatus::ForLvl1Endorsement, $this->currentStatus());
        $this->assertSame(0, ApprovalRecord::query()->count());
    }

    public function test_the_level_1_head_cannot_act_at_level_2(): void
    {
        $this->moveToLevel2();
        $this->actingAs($this->userWithEmployeeId('P-L1'));

        $this->act('approve')->assertForbidden();

        $this->assertSame(WorkOrderStatus::ForLvl2Endorsement, $this->currentStatus());
    }

    public function test_a_non_endorser_gets_403_before_remarks_are_validated(): void
    {
        $this->submitAsRequestor();
        $this->actingAs($this->userWithEmployeeId('P-OTHER'));

        $this->act('reject')->assertForbidden();
    }

    public function test_a_changed_level_1_head_acts_and_the_old_one_gets_403(): void
    {
        $this->submitAsRequestor();
        $this->directory
            ->addEmployee(new Employee(id: 'P-L1B', name: 'New Team Head', immediateHeadId: 'P-L2'))
            ->addEmployee(new Employee(id: 'P-REQ', name: 'Rhea Requestor', immediateHeadId: 'P-L1B'));

        $this->actingAs($this->userWithEmployeeId('P-L1'));
        $this->act('approve')->assertForbidden();

        $newHead = $this->userWithEmployeeId('P-L1B');
        $this->actingAs($newHead);
        $this->act('approve')->assertOk()->assertJsonPath('data.status', 'for_lvl2_endorsement');

        $this->assertSame($newHead->id, ApprovalRecord::query()->sole()->actor_user_id);
    }

    public function test_a_changed_level_2_head_acts_and_the_old_one_gets_403(): void
    {
        $this->moveToLevel2();
        $this->directory
            ->addEmployee(new Employee(id: 'P-L2B', name: 'New Division Head'))
            ->addEmployee(new Employee(id: 'P-L1', name: 'Lito Team Head', immediateHeadId: 'P-L2B'));

        $this->actingAs($this->userWithEmployeeId('P-L2'));
        $this->act('approve')->assertForbidden();

        $this->actingAs($this->userWithEmployeeId('P-L2B'));
        $this->act('approve')->assertOk()->assertJsonPath('data.status', 'for_implementation');
    }

    public function test_a_level_1_return_goes_back_to_open_and_the_creator_can_edit_and_resubmit(): void
    {
        $this->submitAsRequestor();
        $this->actingAs($this->userWithEmployeeId('P-L1'));

        $this->act('return', ['remarks' => 'Split the labor line.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'open');

        $record = ApprovalRecord::query()->sole();
        $this->assertSame(ApprovalAction::Return, $record->action);
        $this->assertSame('Split the labor line.', $record->remarks);
        $this->assertSame('for_lvl1_endorsement', $record->previous_status);
        $this->assertSame('open', $record->resulting_status);

        $this->actingAs($this->requestor);
        $this->post("/api/work-orders/{$this->workOrder->id}", [
            '_method' => 'PATCH',
            'lines' => [['e_call_proposal_line_id' => $this->boqLine->id, 'quantity' => '0.5']],
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.estimated_amount', '50.00');

        $this->act('submit')->assertOk()->assertJsonPath('data.status', 'for_lvl1_endorsement');
    }

    public function test_a_level_2_return_goes_back_to_level_1(): void
    {
        $this->moveToLevel2();
        $this->actingAs($this->userWithEmployeeId('P-L2'));

        $this->act('return', ['remarks' => 'Check the quantity with the Team Head.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'for_lvl1_endorsement');

        $record = ApprovalRecord::query()->orderByDesc('id')->firstOrFail();
        $this->assertSame('for_lvl2_endorsement', $record->previous_status);
        $this->assertSame('for_lvl1_endorsement', $record->resulting_status);

        $this->actingAs($this->userWithEmployeeId('P-L1'));
        $this->act('approve')->assertOk()->assertJsonPath('data.status', 'for_lvl2_endorsement');
    }

    /**
     * @return array<string, array{int}>
     */
    public static function levels(): array
    {
        return ['level 1' => [1], 'level 2' => [2]];
    }

    #[DataProvider('levels')]
    public function test_reject_is_final_from_either_level(int $level): void
    {
        $level === 1 ? $this->submitAsRequestor() : $this->moveToLevel2();
        $this->actingAs($this->userWithEmployeeId("P-L{$level}"));

        $this->act('reject', ['remarks' => 'Not justified.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected');

        $record = ApprovalRecord::query()->orderByDesc('id')->firstOrFail();
        $this->assertSame(ApprovalAction::Reject, $record->action);
        $this->assertSame('rejected', $record->resulting_status);
        $recordCount = ApprovalRecord::query()->count();

        foreach (['P-L1', 'P-L2'] as $endorser) {
            $this->actingAs($this->userWithEmployeeId($endorser));
            $this->act('approve')->assertUnprocessable();
            $this->act('return', ['remarks' => 'x'])->assertUnprocessable();
            $this->act('reject', ['remarks' => 'x'])->assertUnprocessable();
        }

        $this->actingAs($this->requestor);
        $this->act('submit')->assertUnprocessable();
        $this->post("/api/work-orders/{$this->workOrder->id}", [
            '_method' => 'PATCH',
            'lines' => [['e_call_proposal_line_id' => $this->boqLine->id, 'quantity' => '0.5']],
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['work_order']);

        $this->assertSame(WorkOrderStatus::Rejected, $this->currentStatus());
        $this->assertSame($recordCount, ApprovalRecord::query()->count());
    }

    /**
     * @return array<string, array{string, array<string, mixed>}>
     */
    public static function missingRemarks(): array
    {
        return [
            'return without remarks' => ['return', []],
            'return with blank remarks' => ['return', ['remarks' => '   ']],
            'reject without remarks' => ['reject', []],
            'reject with non-string remarks' => ['reject', ['remarks' => ['x']]],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    #[DataProvider('missingRemarks')]
    public function test_return_and_reject_without_remarks_return_422(string $action, array $payload): void
    {
        $this->submitAsRequestor();
        $this->actingAs($this->userWithEmployeeId('P-L1'));

        $this->act($action, $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['remarks']);

        $this->assertSame(WorkOrderStatus::ForLvl1Endorsement, $this->currentStatus());
        $this->assertSame(0, ApprovalRecord::query()->count());
    }

    /**
     * @return array<string, array{WorkOrderStatus}>
     */
    public static function statusesWithoutAnEndorser(): array
    {
        return [
            'open' => [WorkOrderStatus::Open],
            'for implementation' => [WorkOrderStatus::ForImplementation],
            'rejected' => [WorkOrderStatus::Rejected],
        ];
    }

    #[DataProvider('statusesWithoutAnEndorser')]
    public function test_endorsement_actions_outside_an_endorsement_level_return_422(WorkOrderStatus $status): void
    {
        DB::table('work_orders')->where('id', $this->workOrder->id)->update(['status' => $status->value]);
        $this->actingAs($this->userWithEmployeeId('P-L1'));

        $this->act('approve')->assertUnprocessable()->assertJsonValidationErrors(['work_order']);
        $this->act('return', ['remarks' => 'x'])->assertUnprocessable()->assertJsonValidationErrors(['work_order']);
        $this->act('reject', ['remarks' => 'x'])->assertUnprocessable()->assertJsonValidationErrors(['work_order']);

        $this->assertSame($status, $this->currentStatus());
        $this->assertSame(0, ApprovalRecord::query()->count());
    }

    public function test_a_chain_broken_at_level_1_while_waiting_blocks_every_action_until_fixed(): void
    {
        $this->submitAsRequestor();
        $this->directory->addEmployee(new Employee(id: 'P-REQ', name: 'Rhea Requestor'));

        foreach (['P-L1', 'P-OTHER'] as $employeeId) {
            $this->actingAs($this->userWithEmployeeId($employeeId));
            foreach (['approve' => [], 'return' => ['remarks' => 'x'], 'reject' => ['remarks' => 'x']] as $action => $payload) {
                $this->act($action, $payload)
                    ->assertUnprocessable()
                    ->assertJsonValidationErrors(['work_order' => "No level 1 endorser: the Requestor's Immediate Head chain stops before level 1."]);
            }
        }

        $this->assertSame(WorkOrderStatus::ForLvl1Endorsement, $this->currentStatus());
        $this->assertSame(0, ApprovalRecord::query()->count());

        $this->directory
            ->addEmployee(new Employee(id: 'P-L1C', name: 'Fixed Team Head', immediateHeadId: 'P-L2'))
            ->addEmployee(new Employee(id: 'P-REQ', name: 'Rhea Requestor', immediateHeadId: 'P-L1C'));
        $this->actingAs($this->userWithEmployeeId('P-L1C'));

        $this->act('approve')->assertOk()->assertJsonPath('data.status', 'for_lvl2_endorsement');
    }

    public function test_a_chain_broken_at_level_2_while_waiting_returns_422(): void
    {
        $this->moveToLevel2();
        $this->directory->addEmployee(new Employee(id: 'P-L1', name: 'Lito Team Head'));
        $this->actingAs($this->userWithEmployeeId('P-L2'));

        $this->act('approve')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['work_order' => "No level 2 endorser: the Requestor's Immediate Head chain stops before level 2."]);

        $this->assertSame(WorkOrderStatus::ForLvl2Endorsement, $this->currentStatus());
    }

    public function test_an_endorser_replaced_after_the_policy_check_cannot_act(): void
    {
        $this->submitAsRequestor();
        $this->actingAs($this->userWithEmployeeId('P-L1'));

        $replaced = false;
        DB::listen(function ($query) use (&$replaced): void {
            if ($replaced || ! str_contains($query->sql, 'from "e_calls"')) {
                return;
            }

            // The policy has just resolved the level 1 head; the org chart changes before the action commits.
            $replaced = true;
            $this->directory
                ->addEmployee(new Employee(id: 'P-L1B', name: 'New Team Head', immediateHeadId: 'P-L2'))
                ->addEmployee(new Employee(id: 'P-REQ', name: 'Rhea Requestor', immediateHeadId: 'P-L1B'));
        });

        $this->act('approve')->assertForbidden();

        $this->assertTrue($replaced);
        $this->assertSame(WorkOrderStatus::ForLvl1Endorsement, $this->currentStatus());
        $this->assertSame(0, ApprovalRecord::query()->count());
    }

    public function test_the_model_rechecks_the_endorser_inside_the_lock(): void
    {
        $this->submitAsRequestor();

        try {
            WorkOrder::endorse($this->workOrder, $this->userWithEmployeeId('P-L2'), ApprovalAction::Approve, null);
            $this->fail('Only the current level 1 endorser may act.');
        } catch (AuthorizationException) {
            // Expected.
        }

        try {
            WorkOrder::submit($this->workOrder->refresh(), $this->userWithEmployeeId('P-REQ'));
            $this->fail('Only the creator may submit.');
        } catch (AuthorizationException) {
            // Expected.
        }

        $this->assertSame(WorkOrderStatus::ForLvl1Endorsement, $this->currentStatus());
        $this->assertSame(0, ApprovalRecord::query()->count());
    }

    public function test_patch_is_refused_in_every_status_but_open(): void
    {
        $this->actingAs($this->requestor);
        $patch = fn (): TestResponse => $this->post("/api/work-orders/{$this->workOrder->id}", [
            '_method' => 'PATCH',
            'lines' => [['e_call_proposal_line_id' => $this->boqLine->id, 'quantity' => '0.5']],
        ], ['Accept' => 'application/json']);

        foreach ([WorkOrderStatus::ForLvl1Endorsement, WorkOrderStatus::ForLvl2Endorsement, WorkOrderStatus::ForImplementation, WorkOrderStatus::Rejected] as $status) {
            DB::table('work_orders')->where('id', $this->workOrder->id)->update(['status' => $status->value]);
            $patch()->assertUnprocessable()->assertJsonValidationErrors(['work_order']);
        }

        DB::table('work_orders')->where('id', $this->workOrder->id)->update(['status' => WorkOrderStatus::Open->value]);
        $patch()->assertOk();
    }

    public function test_the_approval_history_is_listed_oldest_first(): void
    {
        $this->moveToLevel2();
        Carbon::setTestNow('2026-11-24 10:00:00');
        $this->actingAs($this->userWithEmployeeId('P-L2'));
        $this->act('return', ['remarks' => 'Re-check.'])->assertOk();

        $this->actingAs($this->requestor);
        $this->getJson("/api/work-orders/{$this->workOrder->id}/approvals")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.action', 'approve')
            ->assertJsonPath('data.0.actor.employee_id', 'P-L1')
            ->assertJsonPath('data.0.previous_status', 'for_lvl1_endorsement')
            ->assertJsonPath('data.0.resulting_status', 'for_lvl2_endorsement')
            ->assertJsonPath('data.0.created_at', '2026-11-23T01:00:00.000000Z')
            ->assertJsonPath('data.1.action', 'return')
            ->assertJsonPath('data.1.remarks', 'Re-check.')
            ->assertJsonPath('data.1.resulting_status', 'for_lvl1_endorsement')
            ->assertJsonPath('data.1.created_at', '2026-11-24T02:00:00.000000Z');
    }

    /**
     * @return array<string, array{Closure(): User}>
     */
    public static function usersWhoCanView(): array
    {
        return [
            'the requestor' => [fn (): User => User::query()->where('employee_id', 'P-REQ')->firstOrFail()],
            'a non-vendor user' => [fn (): User => User::factory()->create()],
            'site vendor staff' => [fn (): User => User::factory()->vendorStaff(Vendor::query()->orderBy('id')->firstOrFail())->create()],
        ];
    }

    /**
     * @param  Closure(): User  $makeUser
     */
    #[DataProvider('usersWhoCanView')]
    public function test_the_approval_history_is_visible_like_the_work_order(Closure $makeUser): void
    {
        $this->moveToLevel2();
        $this->actingAs($makeUser());

        $this->getJson("/api/work-orders/{$this->workOrder->id}/approvals")->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_staff_of_another_vendor_cannot_read_the_approval_history(): void
    {
        $this->actingAs(User::factory()->vendorStaff()->create());

        $this->getJson("/api/work-orders/{$this->workOrder->id}/approvals")->assertForbidden();
    }

    public function test_the_morph_map_has_a_stable_work_order_alias(): void
    {
        $this->assertSame(WorkOrder::class, Relation::getMorphedModel('work_order'));
        $this->assertSame('work_order', $this->workOrder->getMorphClass());
    }

    public function test_the_work_order_statuses(): void
    {
        $this->assertSame(
            ['open', 'for_lvl1_endorsement', 'for_lvl2_endorsement', 'for_implementation', 'implemented', 'rejected'],
            array_column(WorkOrderStatus::cases(), 'value'),
        );
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function allEndpoints(): array
    {
        return [
            'submit' => ['post', 'submit'],
            'approve' => ['post', 'approve'],
            'return' => ['post', 'return'],
            'reject' => ['post', 'reject'],
            'approvals' => ['get', 'approvals'],
        ];
    }

    #[DataProvider('allEndpoints')]
    public function test_every_endpoint_returns_401_when_unauthenticated(string $method, string $path): void
    {
        $this->json($method, "/api/work-orders/{$this->workOrder->id}/{$path}", ['remarks' => 'x'])->assertUnauthorized();
    }
}
