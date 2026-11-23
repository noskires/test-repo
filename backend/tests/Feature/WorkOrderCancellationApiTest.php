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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Cancelling a Work Order (issue 25): its creator or the current Site EIC of its E-Call's Site, with remarks, before
 * implementation is authorised (Open, For Lvl1 Endorsement or For Lvl2 Endorsement).
 * Chain in the fake directory: P-REQ (Requestor) -> P-L1 (level 1) -> P-L2 (level 2).
 */
class WorkOrderCancellationApiTest extends TestCase
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

        Carbon::setTestNow('2026-11-24 09:00:00');

        $this->directory = $this->app->make(MasterDataDirectory::class);
        $this->directory
            ->addEmployee(new Employee(id: 'P-REQ', name: 'Rhea Requestor', immediateHeadId: 'P-L1'))
            ->addEmployee(new Employee(id: 'P-L1', name: 'Lito Team Head', immediateHeadId: 'P-L2'))
            ->addEmployee(new Employee(id: 'P-L2', name: 'Dina Division Head'));

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

    /**
     * @param  array<string, mixed>  $payload
     */
    private function cancel(array $payload = ['remarks' => 'No longer needed.']): TestResponse
    {
        return $this->postJson("/api/work-orders/{$this->workOrder->id}/cancel", $payload);
    }

    private function setStatus(WorkOrderStatus $status): void
    {
        DB::table('work_orders')->where('id', $this->workOrder->id)->update(['status' => $status->value]);
    }

    private function employee(string $employeeId): User
    {
        return User::factory()->create(['employee_id' => $employeeId]);
    }

    private function assertUnchanged(WorkOrderStatus $status): void
    {
        $this->assertSame($status, $this->workOrder->refresh()->status);
        $this->assertSame(0, ApprovalRecord::query()->where('action', ApprovalAction::Cancel)->count());
    }

    /**
     * @return array<string, array{WorkOrderStatus}>
     */
    public static function cancellableStatuses(): array
    {
        return [
            'open' => [WorkOrderStatus::Open],
            'for lvl1 endorsement' => [WorkOrderStatus::ForLvl1Endorsement],
            'for lvl2 endorsement' => [WorkOrderStatus::ForLvl2Endorsement],
        ];
    }

    #[DataProvider('cancellableStatuses')]
    public function test_the_creator_cancels_and_one_approval_record_is_written(WorkOrderStatus $status): void
    {
        $this->setStatus($status);
        $this->actingAs($this->requestor);

        $this->cancel(['remarks' => 'Budget was cut.'])
            ->assertOk()
            ->assertJsonPath('data.id', $this->workOrder->id)
            ->assertJsonPath('data.status', 'cancelled');

        $this->assertSame(WorkOrderStatus::Cancelled, $this->workOrder->refresh()->status);

        $record = ApprovalRecord::query()->sole();
        $this->assertSame('work_order', $record->subject_type);
        $this->assertSame($this->workOrder->id, $record->subject_id);
        $this->assertSame(ApprovalAction::Cancel, $record->action);
        $this->assertSame($this->requestor->id, $record->actor_user_id);
        $this->assertSame('P-REQ', $record->actor_employee_id);
        $this->assertSame('Budget was cut.', $record->remarks);
        $this->assertSame($status->value, $record->previous_status);
        $this->assertSame('cancelled', $record->resulting_status);
    }

    #[DataProvider('cancellableStatuses')]
    public function test_the_current_site_eic_cancels(WorkOrderStatus $status): void
    {
        $this->setStatus($status);
        $eic = $this->employee('P-EIC');
        $this->actingAs($eic);

        $this->cancel()->assertOk()->assertJsonPath('data.status', 'cancelled');

        $record = ApprovalRecord::query()->sole();
        $this->assertSame($eic->id, $record->actor_user_id);
        $this->assertSame('P-EIC', $record->actor_employee_id);
    }

    public function test_the_cancel_shows_in_the_approval_history_after_the_endorsements(): void
    {
        $this->actingAs($this->requestor);
        $this->postJson("/api/work-orders/{$this->workOrder->id}/submit")->assertOk();
        $this->actingAs($this->employee('P-L1'));
        $this->postJson("/api/work-orders/{$this->workOrder->id}/approve")->assertOk();

        $this->actingAs($this->requestor);
        $this->cancel(['remarks' => 'Vendor no longer available.'])->assertOk();

        $this->getJson("/api/work-orders/{$this->workOrder->id}/approvals")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.1.action', 'cancel')
            ->assertJsonPath('data.1.remarks', 'Vendor no longer available.')
            ->assertJsonPath('data.1.previous_status', 'for_lvl2_endorsement')
            ->assertJsonPath('data.1.resulting_status', 'cancelled');
    }

    /**
     * @return array<string, array{Closure(Vendor): User}>
     */
    public static function usersWhoMayNotCancel(): array
    {
        return [
            'the level 1 immediate head' => [fn (Vendor $vendor): User => User::factory()->create(['employee_id' => 'P-L1'])],
            'the level 2 immediate head' => [fn (Vendor $vendor): User => User::factory()->create(['employee_id' => 'P-L2'])],
            'site vendor staff' => [fn (Vendor $vendor): User => User::factory()->vendorStaff($vendor)->create()],
            'site vendor staff with the eic employee id' => [fn (Vendor $vendor): User => User::factory()->vendorStaff($vendor)->create(['employee_id' => 'P-EIC'])],
            'another vendor staff' => [fn (Vendor $vendor): User => User::factory()->vendorStaff(Vendor::factory()->create())->create()],
            'another employee' => [fn (Vendor $vendor): User => User::factory()->create(['employee_id' => 'P-OTHER'])],
        ];
    }

    /**
     * @param  Closure(Vendor): User  $makeUser
     */
    #[DataProvider('usersWhoMayNotCancel')]
    public function test_only_the_creator_or_the_current_site_eic_may_cancel(Closure $makeUser): void
    {
        $this->setStatus(WorkOrderStatus::ForLvl1Endorsement);
        $this->actingAs($makeUser($this->siteVendor));

        $this->cancel()->assertForbidden();

        $this->assertUnchanged(WorkOrderStatus::ForLvl1Endorsement);
    }

    public function test_a_new_site_eic_cancels_and_the_replaced_eic_gets_403(): void
    {
        SiteSetting::query()->where('site_id', 'S-1')->update(['eic_employee_id' => 'P-EIC2']);

        $this->actingAs($this->employee('P-EIC'));
        $this->cancel()->assertForbidden();
        $this->assertUnchanged(WorkOrderStatus::Open);

        $this->actingAs($this->employee('P-EIC2'));
        $this->cancel()->assertOk()->assertJsonPath('data.status', 'cancelled');
    }

    public function test_an_outsider_gets_403_regardless_of_status_or_remarks(): void
    {
        $this->setStatus(WorkOrderStatus::Closed);
        $this->actingAs($this->employee('P-OTHER'));

        $this->cancel([])->assertForbidden();

        $this->assertUnchanged(WorkOrderStatus::Closed);
    }

    /**
     * @return array<string, array{WorkOrderStatus}>
     */
    public static function statusesThatCannotBeCancelled(): array
    {
        return [
            'for implementation' => [WorkOrderStatus::ForImplementation],
            'implemented' => [WorkOrderStatus::Implemented],
            'billing lvl1' => [WorkOrderStatus::ForBillingEndorsementLvl1],
            'billing lvl2' => [WorkOrderStatus::ForBillingEndorsementLvl2],
            'billing lvl3' => [WorkOrderStatus::ForBillingEndorsementLvl3],
            'billing lvl4' => [WorkOrderStatus::ForBillingEndorsementLvl4],
            'closed' => [WorkOrderStatus::Closed],
            'rejected' => [WorkOrderStatus::Rejected],
            'cancelled' => [WorkOrderStatus::Cancelled],
        ];
    }

    #[DataProvider('statusesThatCannotBeCancelled')]
    public function test_cancelling_from_for_implementation_on_returns_422(WorkOrderStatus $status): void
    {
        $this->setStatus($status);

        foreach ([$this->requestor, $this->employee('P-EIC')] as $user) {
            $this->actingAs($user);
            $this->cancel()->assertUnprocessable()->assertJsonValidationErrors(['work_order']);
        }

        $this->assertUnchanged($status);
    }

    public function test_every_work_order_status_is_either_cancellable_or_not(): void
    {
        $covered = array_merge(
            array_column(self::cancellableStatuses(), 0),
            array_column(self::statusesThatCannotBeCancelled(), 0),
        );

        $this->assertEqualsCanonicalizing(WorkOrderStatus::cases(), $covered);
        $this->assertSame(
            ['open', 'for_lvl1_endorsement', 'for_lvl2_endorsement'],
            array_column(array_values(array_filter(WorkOrderStatus::cases(), fn (WorkOrderStatus $status): bool => $status->isCancellable())), 'value'),
        );
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function missingRemarks(): array
    {
        return [
            'missing' => [[]],
            'empty' => [['remarks' => '']],
            'blank' => [['remarks' => '   ']],
            'not a string' => [['remarks' => ['x']]],
            'too long' => [['remarks' => str_repeat('a', 5001)]],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    #[DataProvider('missingRemarks')]
    public function test_remarks_are_required(array $payload): void
    {
        foreach ([$this->requestor, $this->employee('P-EIC')] as $user) {
            $this->actingAs($user);
            $this->cancel($payload)->assertUnprocessable()->assertJsonValidationErrors(['remarks']);
        }

        $this->assertUnchanged(WorkOrderStatus::Open);
    }

    public function test_the_e_call_stays_closed_and_a_second_work_order_is_refused(): void
    {
        $this->actingAs($this->requestor);
        $this->cancel()->assertOk();

        $this->assertSame(ECallStatus::Closed, $this->eCall->refresh()->status);

        $this->post("/api/e-calls/{$this->eCall->id}/work-order", [
            'lines' => [['e_call_proposal_line_id' => $this->boqLine->id, 'quantity' => '1']],
        ], ['Accept' => 'application/json'])->assertUnprocessable();

        $this->assertSame(1, WorkOrder::query()->where('e_call_id', $this->eCall->id)->count());
    }

    public function test_a_cancelled_work_order_cannot_be_edited_submitted_or_endorsed(): void
    {
        $this->actingAs($this->requestor);
        $this->cancel()->assertOk();

        $this->post("/api/work-orders/{$this->workOrder->id}", [
            '_method' => 'PATCH',
            'lines' => [['e_call_proposal_line_id' => $this->boqLine->id, 'quantity' => '0.5']],
        ], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->postJson("/api/work-orders/{$this->workOrder->id}/submit")->assertUnprocessable();

        foreach (['P-L1', 'P-L2'] as $head) {
            $this->actingAs($this->employee($head));
            foreach (['approve' => [], 'return' => ['remarks' => 'x'], 'reject' => ['remarks' => 'x']] as $action => $payload) {
                $this->postJson("/api/work-orders/{$this->workOrder->id}/{$action}", $payload)->assertUnprocessable();
            }
        }

        $this->assertSame(WorkOrderStatus::Cancelled, $this->workOrder->refresh()->status);
        $this->assertSame(1, ApprovalRecord::query()->count());
    }

    public function test_a_cancelled_work_order_leaves_the_waiting_list(): void
    {
        $this->actingAs($this->requestor);
        $items = $this->getJson('/api/me/waiting')->assertOk()->json('data');
        $this->assertSame([['work_order', $this->workOrder->id]], array_map(fn (array $item): array => [$item['type'], $item['id']], $items));

        $this->cancel()->assertOk();

        foreach ([$this->requestor, $this->employee('P-L1'), $this->employee('P-EIC'), User::factory()->vendorStaff($this->siteVendor)->create()] as $user) {
            $this->actingAs($user);
            $this->getJson('/api/me/waiting')->assertOk()->assertJsonCount(0, 'data');
        }
    }

    public function test_a_status_changed_after_the_request_check_returns_422(): void
    {
        $this->setStatus(WorkOrderStatus::ForLvl2Endorsement);
        $this->actingAs($this->employee('P-EIC'));

        $changed = false;
        DB::listen(function ($query) use (&$changed): void {
            if ($changed || ! str_contains($query->sql, 'site_settings') || ! str_contains($query->sql, 'eic_employee_id')) {
                return;
            }

            // The level 2 head approves between the policy check and this request's lock.
            $changed = true;
            DB::table('work_orders')->where('id', $this->workOrder->id)->update(['status' => WorkOrderStatus::ForImplementation->value]);
        });

        $this->cancel()->assertUnprocessable()->assertJsonValidationErrors(['work_order']);

        $this->assertTrue($changed);
        $this->assertUnchanged(WorkOrderStatus::ForImplementation);
    }

    public function test_the_model_rechecks_the_actor_inside_the_lock(): void
    {
        try {
            WorkOrder::cancel($this->workOrder, $this->employee('P-L1'), 'Not mine to cancel.');
            $this->fail('An Immediate Head may not cancel.');
        } catch (AuthorizationException) {
            // Expected.
        }

        $this->assertUnchanged(WorkOrderStatus::Open);
    }

    public function test_cancel_returns_401_when_unauthenticated_and_404_for_an_unknown_work_order(): void
    {
        $this->cancel()->assertUnauthorized();

        $this->actingAs($this->requestor);
        $this->postJson('/api/work-orders/999999/cancel', ['remarks' => 'x'])->assertNotFound();
    }
}
