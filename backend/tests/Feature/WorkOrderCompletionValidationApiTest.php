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
use App\Models\WorkOrderLine;
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
 * Issue 21: the current Site EIC validates completed work, sets the Billing Amount and moves the Work Order into
 * billing endorsement. Chain in the fake directory: P-REQ -> P-L1 -> P-L2 -> P-L3 -> P-L4 -> P-L5.
 * Approved lines: paint 2.5 x 123.45, labor 4 x 500.00, bolts A 1 x 1.01, bolts B 1 x 1.01.
 */
class WorkOrderCompletionValidationApiTest extends TestCase
{
    use RefreshDatabase;

    private InMemoryMasterDataDirectory $directory;

    private Vendor $siteVendor;

    private User $requestor;

    private WorkOrder $workOrder;

    private WorkOrderLine $paint;

    private WorkOrderLine $labor;

    private WorkOrderLine $boltsA;

    private WorkOrderLine $boltsB;

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
        $this->requestor = User::factory()->create(['employee_id' => 'P-REQ']);
        $this->workOrder = $this->workOrderFor($this->siteVendor, $this->requestor);
        [$this->paint, $this->labor, $this->boltsA, $this->boltsB] = $this->workOrder->lines->all();

        $this->declareActuals([2_750, 1_500, 1_000, 1_000]);
    }

    private function setHead(string $employeeId, ?string $headId): void
    {
        $this->directory->addEmployee(new Employee(id: $employeeId, name: "Employee {$employeeId}", immediateHeadId: $headId));
    }

    private function workOrderFor(Vendor $vendor, User $requestor): WorkOrder
    {
        $eCall = ECall::factory()->create([
            'site_id' => 'S-1',
            'vendor_id' => $vendor->id,
            'requestor_user_id' => $requestor->id,
            'requestor_employee_id' => $requestor->employee_id,
            'status' => ECallStatus::Approved,
        ]);
        $proposal = $eCall->proposals()->create([
            'revision' => 1,
            'assessment_notes' => 'Repaint and repair.',
            'fsr_disk' => 'local',
            'fsr_path' => 'fsr.pdf',
            'fsr_original_name' => 'fsr.pdf',
            'fsr_mime_type' => 'application/pdf',
            'fsr_size_bytes' => 100,
            'total_millicentavos' => 0,
            'total_centavos' => 0,
            'submitted_by_user_id' => User::factory()->vendorStaff($vendor)->create()->id,
            'submitted_at' => now(),
        ]);

        $lines = [];
        foreach ([['Paint, 4L', 'gal', 2_500, 12_345], ['Repair labor', 'hour', 4_000, 50_000], ['Bolts A', 'set', 1_000, 101], ['Bolts B', 'set', 1_000, 101]] as [$description, $unit, $quantity, $price]) {
            $item = PriceListItem::factory()->create(['vendor_id' => $vendor->id, 'unit_price_centavos' => $price]);
            $lines[] = ['line' => $proposal->lines()->create([
                'price_list_item_id' => $item->id,
                'code' => $item->code,
                'description' => $description,
                'type' => PriceListItemType::Material,
                'unit_of_measure' => $unit,
                'quantity_thousandths' => $quantity,
                'unit_price_centavos' => $price,
                'line_total_millicentavos' => $quantity * $price,
            ]), 'quantity_thousandths' => $quantity];
        }
        $eCall->update(['approved_proposal_id' => $proposal->id]);

        return WorkOrder::createFromECall($eCall, $requestor, $lines, [])->load('lines');
    }

    private function setStatus(WorkOrderStatus $status): void
    {
        DB::table('work_orders')->where('id', $this->workOrder->id)->update(['status' => $status->value]);
    }

    /**
     * The Site Vendor declares completion with these actual quantities (thousandths, in line order): Implemented.
     *
     * @param  list<int>  $actualThousandths
     */
    private function declareActuals(array $actualThousandths): void
    {
        $this->setStatus(WorkOrderStatus::ForImplementation);
        $lines = array_map(
            fn (WorkOrderLine $line, int $quantity): array => ['line' => $line, 'quantity_thousandths' => $quantity],
            $this->workOrder->lines->all(),
            $actualThousandths,
        );

        WorkOrderCompletion::submit(
            $this->workOrder,
            User::factory()->vendorStaff($this->siteVendor)->create(),
            null,
            $lines,
            [UploadedFile::fake()->create('report.pdf', 10, 'application/pdf')],
            [],
        );
    }

    private function eic(string $employeeId = 'P-EIC'): User
    {
        return User::factory()->create(['employee_id' => $employeeId]);
    }

    /**
     * @param  array<string, string>  $validated  line property => validated quantity
     * @return array<string, mixed>
     */
    private function validationPayload(array $validated = [], array $extra = []): array
    {
        $validated += ['paint' => '2.5', 'labor' => '3', 'boltsA' => '0.5', 'boltsB' => '0.5'];

        return [
            'lines' => array_map(
                fn (string $property): array => ['work_order_line_id' => $this->{$property}->id, 'validated_quantity' => $validated[$property]],
                ['paint', 'labor', 'boltsA', 'boltsB'],
            ),
            ...$extra,
        ];
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

    private function assertNothingStored(WorkOrderStatus $expectedStatus = WorkOrderStatus::Implemented): void
    {
        $this->workOrder->refresh();
        $this->assertSame($expectedStatus, $this->workOrder->status);
        $this->assertNull($this->workOrder->billing_amount_centavos);
        $this->assertNull($this->workOrder->billing_amount_millicentavos);
        $this->assertSame([null, null, null, null], $this->workOrder->lines()->pluck('validated_quantity_thousandths')->all());
        $this->assertSame(0, ApprovalRecord::query()->count());
    }

    public function test_the_work_order_marks_each_lines_discrepancy_against_the_latest_completion(): void
    {
        $this->actingAs($this->eic());

        $this->getJson("/api/work-orders/{$this->workOrder->id}")
            ->assertOk()
            ->assertJsonPath('data.has_discrepancies', true)
            ->assertJsonPath('data.lines.0.approved_quantity', '2.500')
            ->assertJsonPath('data.lines.0.actual_quantity', '2.750')
            ->assertJsonPath('data.lines.0.difference', '0.250')
            ->assertJsonPath('data.lines.0.difference_amount_exact', '30.86250')
            ->assertJsonPath('data.lines.0.discrepancy', 'over')
            ->assertJsonPath('data.lines.1.actual_quantity', '1.500')
            ->assertJsonPath('data.lines.1.difference', '-2.500')
            ->assertJsonPath('data.lines.1.difference_amount_exact', '-1250.00000')
            ->assertJsonPath('data.lines.1.discrepancy', 'under')
            ->assertJsonPath('data.lines.2.difference', '0.000')
            ->assertJsonPath('data.lines.2.discrepancy', 'equal');
    }

    public function test_the_flag_is_false_when_the_latest_completion_matches_every_approved_quantity(): void
    {
        $this->setStatus(WorkOrderStatus::ForImplementation);
        $this->declareActuals([2_500, 4_000, 1_000, 1_000]);
        $this->actingAs($this->eic());

        $this->getJson("/api/work-orders/{$this->workOrder->id}")
            ->assertOk()
            ->assertJsonPath('data.has_discrepancies', false)
            ->assertJsonPath('data.lines.0.discrepancy', 'equal')
            ->assertJsonPath('data.lines.1.discrepancy', 'equal');
    }

    public function test_the_completion_history_marks_discrepancies_per_revision(): void
    {
        $this->actingAs($this->eic());

        $this->getJson("/api/work-orders/{$this->workOrder->id}/completions")
            ->assertOk()
            ->assertJsonPath('data.0.has_discrepancies', true)
            ->assertJsonPath('data.0.lines.0.discrepancy', 'over')
            ->assertJsonPath('data.0.lines.0.difference_amount_exact', '30.86250')
            ->assertJsonPath('data.0.lines.1.discrepancy', 'under')
            ->assertJsonPath('data.0.lines.3.discrepancy', 'equal');
    }

    public function test_before_any_completion_there_is_no_discrepancy_yet(): void
    {
        $fresh = $this->workOrderFor($this->siteVendor, User::factory()->create(['employee_id' => 'P-R2']));
        $this->actingAs($this->eic());

        $this->getJson("/api/work-orders/{$fresh->id}")
            ->assertOk()
            ->assertJsonPath('data.has_discrepancies', null)
            ->assertJsonPath('data.lines.0.actual_quantity', null)
            ->assertJsonPath('data.lines.0.discrepancy', null)
            ->assertJsonPath('data.billing_amount', null);
    }

    public function test_the_eic_approves_with_validated_quantities_and_the_work_order_enters_billing(): void
    {
        $eic = $this->eic();
        $this->actingAs($eic);

        $this->act('validate-completion', $this->validationPayload(extra: ['remarks' => 'Labor hours corrected.']))
            ->assertOk()
            ->assertJsonPath('data.status', 'for_billing_endorsement_lvl1')
            ->assertJsonPath('data.billing_amount_exact', '1809.63500')
            ->assertJsonPath('data.billing_amount', '1809.64')
            ->assertJsonPath('data.estimated_amount', '2310.65')
            ->assertJsonPath('data.lines.1.validated_quantity', '3.000')
            ->assertJsonPath('data.lines.1.actual_quantity', '1.500');

        $this->workOrder->refresh();
        $this->assertSame(180_963_500, $this->workOrder->billing_amount_millicentavos);
        $this->assertSame(180_964, $this->workOrder->billing_amount_centavos);
        $this->assertSame([2_500, 3_000, 500, 500], $this->workOrder->lines()->orderBy('id')->pluck('validated_quantity_thousandths')->all());
        $this->assertSame(
            [2_750, 1_500, 1_000, 1_000],
            WorkOrderCompletion::query()->sole()->lines()->orderBy('work_order_line_id')->pluck('actual_quantity_thousandths')->all(),
            "The vendor's actual quantities are kept unchanged.",
        );

        $record = ApprovalRecord::query()->sole();
        $this->assertSame('work_order', $record->subject_type);
        $this->assertSame(ApprovalAction::Approve, $record->action);
        $this->assertSame($eic->id, $record->actor_user_id);
        $this->assertSame('P-EIC', $record->actor_employee_id);
        $this->assertSame('Labor hours corrected.', $record->remarks);
        $this->assertSame('implemented', $record->previous_status);
        $this->assertSame('for_billing_endorsement_lvl1', $record->resulting_status);
    }

    public function test_the_billing_amount_rounds_the_exact_sum_once_not_each_line(): void
    {
        $this->actingAs($this->eic());

        $this->act('validate-completion', $this->validationPayload(['paint' => '0', 'labor' => '0', 'boltsA' => '0.5', 'boltsB' => '0.5']))
            ->assertOk()
            ->assertJsonPath('data.billing_amount_exact', '1.01000')
            ->assertJsonPath('data.billing_amount', '1.01');

        $this->assertSame(101, $this->workOrder->refresh()->billing_amount_centavos, 'Rounding each line first would give 51 + 51 = 102.');
    }

    public function test_a_validated_quantity_above_the_approved_quantity_is_allowed(): void
    {
        $this->actingAs($this->eic());

        $this->act('validate-completion', $this->validationPayload(['paint' => '2.75']))
            ->assertOk()
            ->assertJsonPath('data.lines.0.validated_quantity', '2.750');
    }

    /**
     * @return array<string, array{Closure(self): array<string, mixed>, string}>
     */
    public static function invalidValidations(): array
    {
        return [
            'a missing line' => [fn (self $t): array => ['lines' => array_slice($t->validationPayload()['lines'], 0, 3)], 'lines'],
            'a duplicate line' => [fn (self $t): array => ['lines' => [...$t->validationPayload()['lines'], ['work_order_line_id' => $t->paint->id, 'validated_quantity' => '1']]], 'lines.4.work_order_line_id'],
            'a line from another work order' => [fn (self $t): array => ['lines' => [
                ...array_slice($t->validationPayload()['lines'], 0, 3),
                ['work_order_line_id' => $t->workOrderFor($t->siteVendor, User::factory()->create(['employee_id' => 'P-R3']))->lines->first()->id, 'validated_quantity' => '1'],
            ]], 'lines.3.work_order_line_id'],
            'a negative quantity' => [fn (self $t): array => $t->validationPayload(['paint' => '-1']), 'lines.0.validated_quantity'],
            'four decimals' => [fn (self $t): array => $t->validationPayload(['paint' => '1.2345']), 'lines.0.validated_quantity'],
            'no lines' => [fn (self $t): array => ['lines' => []], 'lines'],
        ];
    }

    /**
     * @param  Closure(self): array<string, mixed>  $makePayload
     */
    #[DataProvider('invalidValidations')]
    public function test_invalid_validated_quantities_return_422(Closure $makePayload, string $field): void
    {
        $this->actingAs($this->eic());

        $this->act('validate-completion', $makePayload($this))
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field]);

        $this->assertNothingStored();
    }

    /**
     * @return array<string, array{WorkOrderStatus}>
     */
    public static function statusesOtherThanImplemented(): array
    {
        return [
            'open' => [WorkOrderStatus::Open],
            'for lvl1 endorsement' => [WorkOrderStatus::ForLvl1Endorsement],
            'for implementation' => [WorkOrderStatus::ForImplementation],
            'for billing endorsement lvl1' => [WorkOrderStatus::ForBillingEndorsementLvl1],
            'rejected' => [WorkOrderStatus::Rejected],
        ];
    }

    #[DataProvider('statusesOtherThanImplemented')]
    public function test_actions_outside_implemented_return_422_before_the_payload_is_checked(WorkOrderStatus $status): void
    {
        $this->setStatus($status);
        $this->actingAs($this->eic());

        foreach (['validate-completion' => [], 'return-completion' => [], 'reject-completion' => []] as $action => $payload) {
            $response = $this->act($action, $payload)->assertUnprocessable()->assertJsonValidationErrors(['work_order']);
            $this->assertSame(['work_order'], array_keys($response->json('errors')), "{$action}: the status is reported alone.");
        }

        $this->assertSame($status, $this->currentStatus());
        $this->assertSame(0, ApprovalRecord::query()->count());
    }

    #[DataProvider('statusesOtherThanImplemented')]
    public function test_a_user_who_cannot_view_the_work_order_gets_a_plain_403_whatever_its_status(WorkOrderStatus $status): void
    {
        $this->setStatus($status);
        $this->actingAs(User::factory()->vendorStaff(Vendor::factory()->create())->create(['employee_id' => 'P-EIC']));

        $this->act('validate-completion', $this->validationPayload())->assertForbidden();
        $this->act('return-completion', ['remarks' => 'x'])->assertForbidden();
        $this->act('reject-completion', ['remarks' => 'x'])->assertForbidden();
    }

    /**
     * @return array<string, array{Closure(): User}>
     */
    public static function viewersWhoAreNotTheCurrentEic(): array
    {
        return [
            'the requestor' => [fn (): User => User::query()->where('employee_id', 'P-REQ')->firstOrFail()],
            'the requestors level 1 head' => [fn (): User => User::factory()->create(['employee_id' => 'P-L1'])],
            'site vendor staff with the eic employee id' => [fn (): User => User::factory()->vendorStaff(Vendor::query()->orderBy('id')->firstOrFail())->create(['employee_id' => 'P-EIC'])],
            'another employee' => [fn (): User => User::factory()->create(['employee_id' => 'P-OTHER'])],
        ];
    }

    /**
     * @param  Closure(): User  $makeUser
     */
    #[DataProvider('viewersWhoAreNotTheCurrentEic')]
    public function test_only_the_current_site_eic_can_act(Closure $makeUser): void
    {
        $this->actingAs($makeUser());

        $this->act('validate-completion', $this->validationPayload())->assertForbidden();
        $this->act('return-completion', ['remarks' => 'x'])->assertForbidden();
        $this->act('reject-completion', ['remarks' => 'x'])->assertForbidden();

        $this->assertNothingStored();
    }

    public function test_after_the_site_eic_is_replaced_the_new_eic_acts_and_the_old_one_gets_403(): void
    {
        SiteSetting::query()->where('site_id', 'S-1')->update(['eic_employee_id' => 'P-NEW']);

        $this->actingAs($this->eic('P-EIC'));
        $this->act('validate-completion', $this->validationPayload())->assertForbidden();

        $newEic = $this->eic('P-NEW');
        $this->actingAs($newEic);
        $this->act('validate-completion', $this->validationPayload())->assertOk();

        $this->assertSame($newEic->id, ApprovalRecord::query()->sole()->actor_user_id);
    }

    public function test_an_eic_replaced_after_the_policy_check_cannot_act(): void
    {
        $this->actingAs($this->eic());

        $replaced = false;
        DB::listen(function ($query) use (&$replaced): void {
            if ($replaced || ! str_contains($query->sql, 'site_settings') || ! str_contains($query->sql, 'eic_employee_id')) {
                return;
            }

            $replaced = true;
            SiteSetting::query()->where('site_id', 'S-1')->update(['eic_employee_id' => 'P-NEW']);
        });

        $this->act('validate-completion', $this->validationPayload())->assertForbidden();

        $this->assertTrue($replaced);
        $this->assertNothingStored();
    }

    public function test_the_model_rechecks_the_eic_inside_the_lock(): void
    {
        $lines = array_map(fn (WorkOrderLine $line): array => ['line' => $line, 'quantity_thousandths' => 1_000], $this->workOrder->lines->all());

        try {
            WorkOrder::validateCompletion($this->workOrder, User::factory()->create(['employee_id' => 'P-OTHER']), $lines, null);
            $this->fail('Only the current Site EIC may validate.');
        } catch (AuthorizationException) {
            // Expected.
        }

        $this->assertNothingStored();
    }

    /**
     * @return array<string, array{Closure(self): void, string}>
     */
    public static function blockedBillingEntries(): array
    {
        return [
            'requestor has no immediate head' => [
                fn (self $t) => $t->setHead('P-REQ', null),
                "No level 1 billing endorser: the Requestor's Immediate Head chain stops before level 1.",
            ],
            'requestor has no employee id' => [
                fn (self $t) => ECall::query()->update(['requestor_employee_id' => null]),
                'The Requestor has no employee ID, so the level 1 billing endorser cannot be found.',
            ],
            'level 1 billing endorser would be the requestor' => [
                fn (self $t) => $t->setHead('P-REQ', 'P-REQ'),
                'The level 1 billing endorser would be the Requestor (P-REQ). Separation of duties requires someone else.',
            ],
            'level 2 billing endorser would be the requestor' => [
                fn (self $t) => $t->setHead('P-L1', 'P-REQ'),
                'The level 2 billing endorser would be the Requestor (P-REQ). Separation of duties requires someone else.',
            ],
            'two billing levels resolve to the same person' => [
                fn (self $t) => $t->setHead('P-L1', 'P-L1'),
                'Two billing endorsement levels resolve to the same person (P-L1). Separation of duties requires different endorsers.',
            ],
        ];
    }

    /**
     * @param  Closure(self): void  $breakChain
     */
    #[DataProvider('blockedBillingEntries')]
    public function test_entering_billing_is_blocked_with_nothing_stored(Closure $breakChain, string $message): void
    {
        $breakChain($this);
        $this->actingAs($this->eic());

        $this->act('validate-completion', $this->validationPayload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['work_order' => $message]);

        $this->assertNothingStored();
    }

    public function test_a_conflict_at_a_level_the_billing_amount_needs_blocks_entry(): void
    {
        // Thresholds low enough that 1,809.64 pesos needs 3 billing levels; level 3 loops back to P-L1.
        BillingThresholdSetting::query()->update(['lower_threshold_centavos' => 100, 'upper_threshold_centavos' => 100_000_000]);
        $this->setHead('P-L2', 'P-L1');
        $this->actingAs($this->eic());

        $this->act('validate-completion', $this->validationPayload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['work_order' => 'Two billing endorsement levels resolve to the same person (P-L1). Separation of duties requires different endorsers.']);

        $this->assertNothingStored();
    }

    public function test_a_conflict_above_the_levels_the_billing_amount_needs_does_not_block(): void
    {
        // Default thresholds: 1,809.64 pesos needs 2 levels; the loop at level 3 doesn't matter.
        $this->setHead('P-L2', 'P-L1');
        $this->actingAs($this->eic());

        $this->act('validate-completion', $this->validationPayload())
            ->assertOk()
            ->assertJsonPath('data.status', 'for_billing_endorsement_lvl1');
    }

    public function test_an_unresolved_level_above_1_does_not_block_entry(): void
    {
        $this->setHead('P-L1', null);
        $this->actingAs($this->eic());

        $this->act('validate-completion', $this->validationPayload())
            ->assertOk()
            ->assertJsonPath('data.status', 'for_billing_endorsement_lvl1');
    }

    public function test_return_sends_the_work_order_back_for_rework_and_the_vendor_resubmits(): void
    {
        $eic = $this->eic();
        $this->actingAs($eic);

        $this->act('return-completion', ['remarks' => 'Labor hours look too low.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'for_implementation');

        $record = ApprovalRecord::query()->sole();
        $this->assertSame(ApprovalAction::Return, $record->action);
        $this->assertSame('Labor hours look too low.', $record->remarks);
        $this->assertSame('implemented', $record->previous_status);
        $this->assertSame('for_implementation', $record->resulting_status);
        $this->assertNull($this->workOrder->refresh()->billing_amount_centavos);

        $this->actingAs(User::factory()->vendorStaff($this->siteVendor)->create());
        $this->post("/api/work-orders/{$this->workOrder->id}/completion", [
            'lines' => array_map(fn (WorkOrderLine $line): array => ['work_order_line_id' => $line->id, 'actual_quantity' => '1'], $this->workOrder->lines->all()),
            'documents' => [UploadedFile::fake()->create('report-v2.pdf', 10, 'application/pdf')],
        ], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.revision', 2);

        $this->actingAs($eic);
        $this->getJson("/api/work-orders/{$this->workOrder->id}")->assertJsonPath('data.lines.0.actual_quantity', '1.000');
        $this->act('validate-completion', $this->validationPayload())->assertOk();
    }

    public function test_reject_is_final(): void
    {
        $this->actingAs($this->eic());

        $this->act('reject-completion', ['remarks' => 'Work not done.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected');

        $this->assertSame(ApprovalAction::Reject, ApprovalRecord::query()->sole()->action);

        $this->act('validate-completion', $this->validationPayload())->assertUnprocessable();
        $this->act('return-completion', ['remarks' => 'x'])->assertUnprocessable();
        $this->act('reject-completion', ['remarks' => 'x'])->assertUnprocessable();

        $this->actingAs(User::factory()->vendorStaff($this->siteVendor)->create());
        $this->post("/api/work-orders/{$this->workOrder->id}/completion", [], ['Accept' => 'application/json'])->assertUnprocessable();

        $this->assertSame(WorkOrderStatus::Rejected, $this->currentStatus());
        $this->assertSame(1, ApprovalRecord::query()->count());
    }

    /**
     * @return array<string, array{string, array<string, mixed>}>
     */
    public static function missingRemarks(): array
    {
        return [
            'return without remarks' => ['return-completion', []],
            'return with blank remarks' => ['return-completion', ['remarks' => '  ']],
            'reject without remarks' => ['reject-completion', []],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    #[DataProvider('missingRemarks')]
    public function test_return_and_reject_without_remarks_return_422(string $action, array $payload): void
    {
        $this->actingAs($this->eic());

        $this->act($action, $payload)->assertUnprocessable()->assertJsonValidationErrors(['remarks']);

        $this->assertNothingStored();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function allEndpoints(): array
    {
        return [
            'validate' => ['validate-completion'],
            'return' => ['return-completion'],
            'reject' => ['reject-completion'],
        ];
    }

    #[DataProvider('allEndpoints')]
    public function test_every_endpoint_returns_401_when_unauthenticated(string $action): void
    {
        $this->act($action, ['remarks' => 'x'])->assertUnauthorized();
    }
}
