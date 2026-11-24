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
use App\Models\BillingThresholdSetting;
use App\Models\ECall;
use App\Models\PriceListItem;
use App\Models\SiteSetting;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkOrder;
use App\Models\WorkOrderCompletion;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Issue 23: billing endorsement up the E-Call Requestor's Immediate Head chain, resolved current at each step, with the
 * required levels recomputed from the rounded Billing Amount and the CURRENT thresholds at every action.
 * Chain in the fake directory: P-REQ -> P-L1 -> P-L2 -> P-L3 -> P-L4 -> P-L5. One line at 1,000.00 pesos per unit.
 * Default thresholds: 200,000 / 1,000,000 pesos.
 */
class WorkOrderBillingEndorsementApiTest extends TestCase
{
    use RefreshDatabase;

    private InMemoryMasterDataDirectory $directory;

    private Vendor $siteVendor;

    private User $requestor;

    private WorkOrder $workOrder;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('pss.e_call_attachments.disk'));

        $this->directory = $this->app->make(MasterDataDirectory::class);
        foreach (['P-REQ' => 'P-L1', 'P-L1' => 'P-L2', 'P-L2' => 'P-L3', 'P-L3' => 'P-L4', 'P-L4' => 'P-L5', 'P-L5' => null] as $id => $head) {
            $this->setHead($id, $head);
        }

        $this->siteVendor = Vendor::factory()->create();
        SiteSetting::factory()->create(['site_id' => 'S-1', 'vendor_id' => $this->siteVendor->id, 'eic_employee_id' => 'P-EIC']);
        $this->requestor = self::employeeUser('P-REQ');

        $eCall = ECall::factory()->create([
            'site_id' => 'S-1',
            'vendor_id' => $this->siteVendor->id,
            'requestor_user_id' => $this->requestor->id,
            'requestor_employee_id' => 'P-REQ',
            'status' => ECallStatus::Approved,
        ]);
        $proposal = $eCall->proposals()->create([
            'revision' => 1,
            'assessment_notes' => 'Building works.',
            'fsr_disk' => 'local',
            'fsr_path' => 'fsr.pdf',
            'fsr_original_name' => 'fsr.pdf',
            'fsr_mime_type' => 'application/pdf',
            'fsr_size_bytes' => 100,
            'total_millicentavos' => 0,
            'total_centavos' => 0,
            'submitted_by_user_id' => User::factory()->vendorStaff($this->siteVendor)->create()->id,
            'submitted_at' => now(),
        ]);
        $item = PriceListItem::factory()->create(['vendor_id' => $this->siteVendor->id, 'unit_price_centavos' => 100_000]);
        $boqLine = $proposal->lines()->create([
            'price_list_item_id' => $item->id,
            'code' => $item->code,
            'description' => 'Building works',
            'type' => PriceListItemType::Service,
            'unit_of_measure' => 'unit',
            'quantity_thousandths' => 2_000_000,
            'unit_price_centavos' => 100_000,
            'line_total_millicentavos' => 2_000_000 * 100_000,
        ]);
        $eCall->update(['approved_proposal_id' => $proposal->id]);

        // The real flow before billing: create, submit, 2-level endorsement (issue 19, by P-L1 and P-L2).
        $this->workOrder = WorkOrder::createFromECall($eCall, $this->requestor, [['line' => $boqLine, 'quantity_thousandths' => 2_000_000]], []);
        WorkOrder::submit($this->workOrder, $this->requestor);
        WorkOrder::endorse($this->workOrder, $this->employee('P-L1'), ApprovalAction::Approve, null);
        WorkOrder::endorse($this->workOrder, $this->employee('P-L2'), ApprovalAction::Approve, null);
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
     * The vendor declares completion and the EIC validates units x 1,000.00 pesos: For Billing Endorsement LVL1.
     */
    private function enterBillingWith(int $units): void
    {
        $line = $this->workOrder->refresh()->lines()->sole();
        $quantity = [['line' => $line, 'quantity_thousandths' => $units * 1_000]];

        WorkOrderCompletion::submit(
            $this->workOrder,
            User::factory()->vendorStaff($this->siteVendor)->create(),
            null,
            $quantity,
            [UploadedFile::fake()->create('report.pdf', 10, 'application/pdf')],
            [],
        );
        WorkOrder::validateCompletion($this->workOrder, $this->employee('P-EIC'), $quantity, null);

        $this->assertSame(WorkOrderStatus::ForBillingEndorsementLvl1, $this->currentStatus());
    }

    private function setThresholds(int $lowerPesos, int $upperPesos): void
    {
        BillingThresholdSetting::query()->update([
            'lower_threshold_centavos' => $lowerPesos * 100,
            'upper_threshold_centavos' => $upperPesos * 100,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function act(string $action, array $payload = []): TestResponse
    {
        return $this->postJson("/api/work-orders/{$this->workOrder->id}/{$action}", $payload);
    }

    private function approveAs(string $employeeId): TestResponse
    {
        $this->actingAs($this->employee($employeeId));

        return $this->act('approve');
    }

    private function currentStatus(): WorkOrderStatus
    {
        return $this->workOrder->refresh()->status;
    }

    private function billingRecordCount(): int
    {
        return ApprovalRecord::query()->where('previous_status', 'like', 'for_billing_endorsement_%')->count();
    }

    /**
     * @return array<string, array{int, list<string>}>
     */
    public static function billingAmounts(): array
    {
        return [
            '150,000 pesos needs 2' => [150, ['for_billing_endorsement_lvl2', 'closed']],
            '200,000 pesos needs 3' => [200, ['for_billing_endorsement_lvl2', 'for_billing_endorsement_lvl3', 'closed']],
            '1,000,000 pesos needs 3' => [1_000, ['for_billing_endorsement_lvl2', 'for_billing_endorsement_lvl3', 'closed']],
            '1,200,000 pesos needs 4' => [1_200, ['for_billing_endorsement_lvl2', 'for_billing_endorsement_lvl3', 'for_billing_endorsement_lvl4', 'closed']],
        ];
    }

    /**
     * @param  list<string>  $expectedStatuses
     */
    #[DataProvider('billingAmounts')]
    public function test_the_billing_amount_decides_how_many_levels_approve_before_closing(int $units, array $expectedStatuses): void
    {
        $this->enterBillingWith($units);

        foreach ($expectedStatuses as $index => $expectedStatus) {
            $level = $index + 1;
            $approver = $this->employee("P-L{$level}");
            $this->actingAs($approver);

            $this->act('approve')->assertOk()->assertJsonPath('data.status', $expectedStatus);

            $record = ApprovalRecord::query()->orderByDesc('id')->firstOrFail();
            $this->assertSame(ApprovalAction::Approve, $record->action);
            $this->assertSame("for_billing_endorsement_lvl{$level}", $record->previous_status);
            $this->assertSame($expectedStatus, $record->resulting_status);
            $this->assertSame($approver->id, $record->actor_user_id);
        }

        $this->assertSame(WorkOrderStatus::Closed, $this->currentStatus());
        $this->assertSame(count($expectedStatuses), $this->billingRecordCount());
    }

    public function test_raising_the_required_levels_mid_way_adds_a_level(): void
    {
        $this->enterBillingWith(150);
        $this->approveAs('P-L1')->assertOk()->assertJsonPath('data.status', 'for_billing_endorsement_lvl2');

        $this->setThresholds(100_000, 1_000_000);

        $this->approveAs('P-L2')->assertOk()->assertJsonPath('data.status', 'for_billing_endorsement_lvl3');
        $this->approveAs('P-L3')->assertOk()->assertJsonPath('data.status', 'closed');
    }

    public function test_lowering_the_required_levels_makes_the_current_level_the_last(): void
    {
        $this->enterBillingWith(1_200);
        $this->approveAs('P-L1')->assertOk()->assertJsonPath('data.status', 'for_billing_endorsement_lvl2');

        $this->setThresholds(2_000_000, 3_000_000);

        $this->approveAs('P-L2')->assertOk()->assertJsonPath('data.status', 'closed');
    }

    public function test_when_the_levels_already_done_suffice_the_current_endorser_still_closes_it(): void
    {
        // Open question 1, option (c): no automatic close; the current level's approve closes the Work Order.
        $this->enterBillingWith(1_200);
        $this->approveAs('P-L1')->assertOk();
        $this->approveAs('P-L2')->assertOk()->assertJsonPath('data.status', 'for_billing_endorsement_lvl3');

        $this->setThresholds(2_000_000, 3_000_000);

        $this->assertSame(WorkOrderStatus::ForBillingEndorsementLvl3, $this->currentStatus());
        $this->approveAs('P-L3')->assertOk()->assertJsonPath('data.status', 'closed');
    }

    /**
     * @return array<string, array{Closure(): User}>
     */
    public static function usersWhoAreNotTheLevel1Endorser(): array
    {
        return [
            'the requestor' => [fn (): User => User::query()->where('employee_id', 'P-REQ')->firstOrFail()],
            'the site eic' => [fn (): User => self::employeeUser('P-EIC')],
            'the level 2 head' => [fn (): User => self::employeeUser('P-L2')],
            'site vendor staff with the level 1 employee id' => [fn (): User => self::vendorStaffWithEmployeeId('P-L1', Vendor::query()->orderBy('id')->firstOrFail())],
        ];
    }

    /**
     * @param  Closure(): User  $makeUser
     */
    #[DataProvider('usersWhoAreNotTheLevel1Endorser')]
    public function test_only_the_current_levels_endorser_can_act(Closure $makeUser): void
    {
        $this->enterBillingWith(150);
        $this->actingAs($makeUser());

        $this->act('approve')->assertForbidden();
        $this->act('return', ['remarks' => 'x'])->assertForbidden();
        $this->act('reject', ['remarks' => 'x'])->assertForbidden();

        $this->assertSame(WorkOrderStatus::ForBillingEndorsementLvl1, $this->currentStatus());
        $this->assertSame(0, $this->billingRecordCount());
    }

    public function test_a_changed_head_at_the_current_level_acts_and_the_old_one_gets_403(): void
    {
        $this->enterBillingWith(150);
        $this->approveAs('P-L1')->assertOk();

        $this->setHead('P-L2B', 'P-L3');
        $this->setHead('P-L1', 'P-L2B');

        $this->approveAs('P-L2')->assertForbidden();
        $this->approveAs('P-L2B')->assertOk()->assertJsonPath('data.status', 'closed');
    }

    public function test_a_user_who_cannot_view_the_work_order_gets_a_plain_403_even_on_a_broken_chain(): void
    {
        $this->enterBillingWith(150);
        $this->setHead('P-REQ', null);
        $this->actingAs(self::vendorStaffWithEmployeeId('P-L1', Vendor::factory()->create()));

        $this->act('approve')->assertForbidden();
        $this->act('return', ['remarks' => 'x'])->assertForbidden();
        $this->act('reject', ['remarks' => 'x'])->assertForbidden();
        $this->getJson("/api/work-orders/{$this->workOrder->id}/approvals")->assertForbidden();
    }

    public function test_an_approve_into_a_level_without_an_endorser_is_blocked_until_the_chain_is_fixed(): void
    {
        $this->enterBillingWith(1_200);
        $this->approveAs('P-L1')->assertOk();
        $this->approveAs('P-L2')->assertOk();
        $this->setHead('P-L3', null);
        $records = ApprovalRecord::query()->count();

        $this->approveAs('P-L3')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['work_order' => "No level 4 billing endorser: the Requestor's Immediate Head chain stops before level 4."]);

        $this->assertSame(WorkOrderStatus::ForBillingEndorsementLvl3, $this->currentStatus());
        $this->assertSame($records, ApprovalRecord::query()->count());

        $this->setHead('P-L3', 'P-L4');
        $this->approveAs('P-L3')->assertOk()->assertJsonPath('data.status', 'for_billing_endorsement_lvl4');
    }

    public function test_when_the_current_levels_endorser_stops_resolving_every_action_returns_422(): void
    {
        $this->enterBillingWith(150);
        $this->approveAs('P-L1')->assertOk();
        $this->setHead('P-L1', null);

        foreach (['P-L2', 'P-OTHER'] as $employeeId) {
            $this->actingAs($this->employee($employeeId));
            foreach (['approve' => [], 'return' => ['remarks' => 'x'], 'reject' => ['remarks' => 'x']] as $action => $payload) {
                $this->act($action, $payload)
                    ->assertUnprocessable()
                    ->assertJsonValidationErrors(['work_order' => "No level 2 billing endorser: the Requestor's Immediate Head chain stops before level 2."]);
            }
        }

        $this->assertSame(WorkOrderStatus::ForBillingEndorsementLvl2, $this->currentStatus());

        $this->setHead('P-L1', 'P-L2C');
        $this->setHead('P-L2C', 'P-L3');
        $this->approveAs('P-L2C')->assertOk()->assertJsonPath('data.status', 'closed');
    }

    /**
     * @return array<string, array{Closure(self): void, string}>
     */
    public static function chainConflicts(): array
    {
        return [
            'level 1 would be the requestor' => [
                fn (self $t) => $t->setHead('P-REQ', 'P-REQ'),
                'The level 1 billing endorser would be the Requestor (P-REQ). Separation of duties requires someone else.',
            ],
            'level 2 would be the requestor' => [
                fn (self $t) => $t->setHead('P-L1', 'P-REQ'),
                'The level 2 billing endorser would be the Requestor (P-REQ). Separation of duties requires someone else.',
            ],
            'levels 1 and 2 are the same person' => [
                fn (self $t) => $t->setHead('P-L1', 'P-L1'),
                'Two billing endorsement levels resolve to the same person (P-L1). Separation of duties requires different endorsers.',
            ],
        ];
    }

    /**
     * @param  Closure(self): void  $makeConflict
     */
    #[DataProvider('chainConflicts')]
    public function test_a_chain_conflict_within_the_required_levels_blocks_every_action(Closure $makeConflict, string $message): void
    {
        $this->enterBillingWith(150);
        $makeConflict($this);

        foreach (['P-L1', 'P-OTHER'] as $employeeId) {
            $this->actingAs($this->employee($employeeId));
            foreach (['approve' => [], 'return' => ['remarks' => 'x'], 'reject' => ['remarks' => 'x']] as $action => $payload) {
                $this->act($action, $payload)->assertUnprocessable()->assertJsonValidationErrors(['work_order' => $message]);
            }
        }

        $this->assertSame(WorkOrderStatus::ForBillingEndorsementLvl1, $this->currentStatus());
        $this->assertSame(0, $this->billingRecordCount());
    }

    public function test_a_conflict_above_the_required_levels_does_not_block(): void
    {
        $this->enterBillingWith(150);
        $this->setHead('P-L2', 'P-L1');

        $this->approveAs('P-L1')->assertOk();
        $this->approveAs('P-L2')->assertOk()->assertJsonPath('data.status', 'closed');
    }

    public function test_a_level_1_approver_who_becomes_the_level_2_head_cannot_approve_level_2(): void
    {
        $this->enterBillingWith(150);
        $levelOneApprover = $this->employee('P-L1');
        $this->actingAs($levelOneApprover);
        $this->act('approve')->assertOk();

        $this->setHead('P-REQ', 'P-L1B');
        $this->setHead('P-L1B', 'P-L1');
        $this->setHead('P-L1', 'P-L3');

        $this->act('approve')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['work_order' => 'You already approved this Work Order at billing level 1 in the current billing round. Separation of duties requires a different endorser at each level.']);
        $this->assertSame(WorkOrderStatus::ForBillingEndorsementLvl2, $this->currentStatus());

        $this->actingAs($this->employee('P-L1'));
        $this->act('approve')->assertUnprocessable()->assertJsonValidationErrors(['work_order']);

        $this->act('return', ['remarks' => 'Over to the new level 1.'])->assertOk()->assertJsonPath('data.status', 'for_billing_endorsement_lvl1');
    }

    public function test_a_previous_billing_approver_may_still_reject(): void
    {
        $this->enterBillingWith(150);
        $this->approveAs('P-L1')->assertOk();
        $this->setHead('P-REQ', 'P-L1B');
        $this->setHead('P-L1B', 'P-L1');
        $this->setHead('P-L1', 'P-L3');

        $this->actingAs($this->employee('P-L1'));
        $this->act('reject', ['remarks' => 'Over budget.'])->assertOk()->assertJsonPath('data.status', 'rejected');
    }

    public function test_re_approving_the_same_level_after_a_return_from_above_is_allowed(): void
    {
        $this->enterBillingWith(1_000);
        $this->approveAs('P-L1')->assertOk();
        $this->actingAs($this->employee('P-L2'));
        $this->act('return', ['remarks' => 'Re-check.'])->assertOk()->assertJsonPath('data.status', 'for_billing_endorsement_lvl1');

        $this->approveAs('P-L1')->assertOk()->assertJsonPath('data.status', 'for_billing_endorsement_lvl2');
    }

    public function test_work_order_endorsement_approvals_do_not_count_as_billing_approvals(): void
    {
        // P-L1 and P-L2 approved the pre-implementation endorsement in setUp; they may approve billing levels 1 and 2.
        $this->enterBillingWith(150);

        $this->approveAs('P-L1')->assertOk();
        $this->approveAs('P-L2')->assertOk()->assertJsonPath('data.status', 'closed');
    }

    public function test_an_earlier_billing_round_does_not_count(): void
    {
        $this->enterBillingWith(150);
        $this->approveAs('P-L1')->assertOk();
        $this->actingAs($this->employee('P-L2'));
        $this->act('return', ['remarks' => 'x'])->assertOk();
        $this->actingAs($this->employee('P-L1'));
        $this->act('return', ['remarks' => 'Back to the EIC.'])->assertOk()->assertJsonPath('data.status', 'implemented');

        $line = $this->workOrder->refresh()->lines()->sole();
        WorkOrder::validateCompletion($this->workOrder, $this->employee('P-EIC'), [['line' => $line, 'quantity_thousandths' => 150_000]], null);
        $this->setHead('P-REQ', 'P-L1B');
        $this->setHead('P-L1B', 'P-L1');
        $this->setHead('P-L1', 'P-L3');

        $this->approveAs('P-L1B')->assertOk();
        $this->approveAs('P-L1')->assertOk()->assertJsonPath('data.status', 'closed');
    }

    public function test_return_from_level_1_goes_back_to_the_eic(): void
    {
        $this->enterBillingWith(150);
        $this->actingAs($this->employee('P-L1'));

        $this->act('return', ['remarks' => 'Quantities look wrong.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'implemented');

        $record = ApprovalRecord::query()->orderByDesc('id')->firstOrFail();
        $this->assertSame(ApprovalAction::Return, $record->action);
        $this->assertSame('for_billing_endorsement_lvl1', $record->previous_status);
        $this->assertSame('implemented', $record->resulting_status);

        $this->actingAs($this->employee('P-EIC'));
        $this->act('validate-completion', ['lines' => [['work_order_line_id' => $this->workOrder->lines()->sole()->id, 'validated_quantity' => '120']]])
            ->assertOk()
            ->assertJsonPath('data.status', 'for_billing_endorsement_lvl1')
            ->assertJsonPath('data.billing_amount', '120000.00');
    }

    public function test_return_from_level_n_goes_back_one_level(): void
    {
        $this->enterBillingWith(1_200);
        $this->approveAs('P-L1')->assertOk();
        $this->approveAs('P-L2')->assertOk();
        $this->actingAs($this->employee('P-L3'));

        $this->act('return', ['remarks' => 'Ask the Division Head.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'for_billing_endorsement_lvl2');
    }

    public function test_reject_is_final(): void
    {
        $this->enterBillingWith(150);
        $this->approveAs('P-L1')->assertOk();
        $this->actingAs($this->employee('P-L2'));

        $this->act('reject', ['remarks' => 'Not payable.'])->assertOk()->assertJsonPath('data.status', 'rejected');

        foreach (['P-L1', 'P-L2'] as $employeeId) {
            $this->actingAs($this->employee($employeeId));
            $this->act('approve')->assertUnprocessable();
            $this->act('return', ['remarks' => 'x'])->assertUnprocessable();
            $this->act('reject', ['remarks' => 'x'])->assertUnprocessable();
        }

        $this->assertSame(WorkOrderStatus::Rejected, $this->currentStatus());
    }

    public function test_a_closed_work_order_accepts_no_further_action(): void
    {
        $this->enterBillingWith(150);
        $this->approveAs('P-L1')->assertOk();
        $this->approveAs('P-L2')->assertOk()->assertJsonPath('data.status', 'closed');

        $this->approveAs('P-L2')->assertUnprocessable()->assertJsonValidationErrors(['work_order']);
        $this->actingAs($this->employee('P-L1'));
        $this->act('return', ['remarks' => 'x'])->assertUnprocessable();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function actionsNeedingRemarks(): array
    {
        return ['return' => ['return'], 'reject' => ['reject']];
    }

    #[DataProvider('actionsNeedingRemarks')]
    public function test_return_and_reject_need_remarks(string $action): void
    {
        $this->enterBillingWith(150);
        $this->actingAs($this->employee('P-L1'));

        $this->act($action, ['remarks' => '  '])->assertUnprocessable()->assertJsonValidationErrors(['remarks']);

        $this->assertSame(WorkOrderStatus::ForBillingEndorsementLvl1, $this->currentStatus());
    }

    public function test_an_endorser_replaced_after_the_policy_check_cannot_act(): void
    {
        $this->enterBillingWith(150);
        $this->actingAs($this->employee('P-L1'));

        $replaced = false;
        DB::listen(function ($query) use (&$replaced): void {
            if ($replaced || ! str_contains($query->sql, 'from "e_calls"')) {
                return;
            }

            $replaced = true;
            $this->setHead('P-L1B', 'P-L2');
            $this->setHead('P-REQ', 'P-L1B');
        });

        $this->act('approve')->assertForbidden();

        $this->assertTrue($replaced);
        $this->assertSame(WorkOrderStatus::ForBillingEndorsementLvl1, $this->currentStatus());
        $this->assertSame(0, $this->billingRecordCount());
    }

    public function test_the_model_rechecks_the_billing_endorser_inside_the_lock(): void
    {
        $this->enterBillingWith(150);

        try {
            WorkOrder::endorse($this->workOrder, $this->employee('P-L2'), ApprovalAction::Approve, null);
            $this->fail('Only the current billing level endorser may act.');
        } catch (AuthorizationException) {
            // Expected.
        }

        $this->assertSame(WorkOrderStatus::ForBillingEndorsementLvl1, $this->currentStatus());
    }

    public function test_the_approval_history_shows_endorsement_validation_and_billing_records_in_order(): void
    {
        $this->enterBillingWith(150);
        $this->approveAs('P-L1')->assertOk();
        $this->approveAs('P-L2')->assertOk();

        $this->actingAs($this->requestor);
        $this->assertSame(
            [
                'for_lvl1_endorsement>for_lvl2_endorsement',
                'for_lvl2_endorsement>for_implementation',
                'implemented>for_billing_endorsement_lvl1',
                'for_billing_endorsement_lvl1>for_billing_endorsement_lvl2',
                'for_billing_endorsement_lvl2>closed',
            ],
            collect($this->getJson("/api/work-orders/{$this->workOrder->id}/approvals")->assertOk()->json('data'))
                ->map(fn (array $record): string => "{$record['previous_status']}>{$record['resulting_status']}")
                ->all(),
        );
    }

    public function test_the_work_order_statuses(): void
    {
        $this->assertSame(
            [
                'open', 'for_lvl1_endorsement', 'for_lvl2_endorsement', 'for_implementation', 'implemented',
                'for_billing_endorsement_lvl1', 'for_billing_endorsement_lvl2', 'for_billing_endorsement_lvl3',
                'for_billing_endorsement_lvl4', 'closed', 'rejected', 'cancelled',
            ],
            array_column(WorkOrderStatus::cases(), 'value'),
        );
    }
}
