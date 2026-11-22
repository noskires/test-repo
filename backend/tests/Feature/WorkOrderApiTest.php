<?php

namespace Tests\Feature;

use App\Enums\ECallStatus;
use App\Enums\PriceListItemType;
use App\Enums\WorkOrderStatus;
use App\Models\ApprovalRecord;
use App\Models\ECall;
use App\Models\ECallProposal;
use App\Models\ECallProposalLine;
use App\Models\PriceListItem;
use App\Models\SiteSetting;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkOrder;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WorkOrderApiTest extends TestCase
{
    use RefreshDatabase;

    private Vendor $siteVendor;

    private User $requestor;

    private ECall $eCall;

    private ECallProposal $approvedProposal;

    private ECallProposalLine $paintLine;

    private ECallProposalLine $laborLine;

    private ECallProposalLine $firstHalfLine;

    private ECallProposalLine $secondHalfLine;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('pss.e_call_attachments.disk'));

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

        $this->proposal(1, [['Old paint', 'can', 5_000, 1_000]]);
        $this->approvedProposal = $this->proposal(2, [
            ['Paint, 4L', 'gal', 2_500, 12_345],
            ['Fence repair labor', 'hour', 1_000, 1_001],
            ['Bolt set A', 'set', 1_000, 101],
            ['Bolt set B', 'set', 1_000, 101],
        ]);
        [$this->paintLine, $this->laborLine, $this->firstHalfLine, $this->secondHalfLine] = $this->approvedProposal->lines->all();
        $this->eCall->update(['approved_proposal_id' => $this->approvedProposal->id]);
    }

    /**
     * @param  list<array{string, string, int, int}>  $lines  description, unit, quantity in thousandths, unit price in centavos
     */
    private function proposal(int $revision, array $lines): ECallProposal
    {
        $proposal = $this->eCall->proposals()->create([
            'revision' => $revision,
            'assessment_notes' => "Revision {$revision}.",
            'fsr_disk' => 'local',
            'fsr_path' => "fsr-{$revision}.pdf",
            'fsr_original_name' => "fsr-{$revision}.pdf",
            'fsr_mime_type' => 'application/pdf',
            'fsr_size_bytes' => 100,
            'total_millicentavos' => 0,
            'total_centavos' => 0,
            'submitted_by_user_id' => User::factory()->vendorStaff($this->siteVendor)->create()->id,
            'submitted_at' => now(),
        ]);

        foreach ($lines as [$description, $unit, $quantityThousandths, $unitPriceCentavos]) {
            $item = PriceListItem::factory()->create(['vendor_id' => $this->siteVendor->id, 'unit_price_centavos' => $unitPriceCentavos]);
            $proposal->lines()->create([
                'price_list_item_id' => $item->id,
                'code' => $item->code,
                'description' => $description,
                'type' => PriceListItemType::Material,
                'unit_of_measure' => $unit,
                'quantity_thousandths' => $quantityThousandths,
                'unit_price_centavos' => $unitPriceCentavos,
                'line_total_millicentavos' => $quantityThousandths * $unitPriceCentavos,
            ]);
        }

        return $proposal->load('lines');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'lines' => [
                ['e_call_proposal_line_id' => $this->paintLine->id, 'quantity' => '2.5'],
                ['e_call_proposal_line_id' => $this->laborLine->id, 'quantity' => '0.333'],
            ],
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function createWorkOrder(array $payload, ?ECall $eCall = null): TestResponse
    {
        $eCall ??= $this->eCall;

        return $this->post("/api/e-calls/{$eCall->id}/work-order", $payload, ['Accept' => 'application/json']);
    }

    private function createAsRequestor(array $payload = []): WorkOrder
    {
        $this->actingAs($this->requestor);
        $id = $this->createWorkOrder($payload === [] ? $this->validPayload() : $payload)->assertCreated()->json('data.id');

        return WorkOrder::query()->findOrFail($id);
    }

    public function test_the_requestor_creates_an_open_work_order_and_the_e_call_closes(): void
    {
        $this->actingAs($this->requestor);

        $response = $this->createWorkOrder($this->validPayload())
            ->assertCreated()
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.e_call_id', $this->eCall->id)
            ->assertJsonPath('data.proposal.id', $this->approvedProposal->id)
            ->assertJsonPath('data.proposal.revision', 2)
            ->assertJsonPath('data.created_by_user_id', $this->requestor->id)
            ->assertJsonCount(2, 'data.lines')
            ->assertJsonPath('data.lines.0.e_call_proposal_line_id', $this->paintLine->id)
            ->assertJsonPath('data.lines.0.description', 'Paint, 4L')
            ->assertJsonPath('data.lines.0.unit_of_measure', 'gal')
            ->assertJsonPath('data.lines.0.quantity', '2.500')
            ->assertJsonPath('data.lines.0.unit_price', '123.45')
            ->assertJsonPath('data.lines.0.line_total', '308.62500')
            ->assertJsonPath('data.lines.1.quantity', '0.333')
            ->assertJsonPath('data.lines.1.line_total', '3.33333')
            ->assertJsonPath('data.estimated_amount_exact', '311.95833')
            ->assertJsonPath('data.estimated_amount', '311.96');

        $workOrder = WorkOrder::query()->with('lines')->sole();
        $this->assertSame($response->json('data.id'), $workOrder->id);
        $this->assertSame(WorkOrderStatus::Open, $workOrder->status);
        $this->assertSame($this->eCall->id, $workOrder->e_call_id);
        $this->assertSame($this->approvedProposal->id, $workOrder->e_call_proposal_id);
        $this->assertSame(31_195_833, $workOrder->estimated_amount_millicentavos);
        $this->assertSame(31_196, $workOrder->estimated_amount_centavos);
        $this->assertSame([2_500, 333], $workOrder->lines->pluck('quantity_thousandths')->all());
        $this->assertSame([12_345, 1_001], $workOrder->lines->pluck('unit_price_centavos')->all());
        $this->assertSame([30_862_500, 333_333], $workOrder->lines->pluck('line_total_millicentavos')->all());

        $this->assertSame(ECallStatus::Closed, $this->eCall->refresh()->status);
        $this->assertSame(0, ApprovalRecord::query()->count(), 'Creating a Work Order is not an approval action.');
    }

    public function test_prices_are_copied_from_the_boq_line_not_the_current_price_list(): void
    {
        PriceListItem::query()->whereKey($this->paintLine->price_list_item_id)->update(['unit_price_centavos' => 99_999, 'description' => 'Renamed']);
        $this->actingAs($this->requestor);

        $this->createWorkOrder($this->validPayload())
            ->assertCreated()
            ->assertJsonPath('data.lines.0.unit_price', '123.45')
            ->assertJsonPath('data.lines.0.description', 'Paint, 4L');
    }

    public function test_the_requestor_may_leave_boq_lines_out_and_use_the_full_boq_quantity(): void
    {
        $this->actingAs($this->requestor);

        $this->createWorkOrder(['lines' => [['e_call_proposal_line_id' => $this->paintLine->id, 'quantity' => '2.500']]])
            ->assertCreated()
            ->assertJsonCount(1, 'data.lines')
            ->assertJsonPath('data.estimated_amount', '308.63');
    }

    public function test_the_estimated_amount_rounds_the_exact_sum_once_not_each_line(): void
    {
        $this->actingAs($this->requestor);

        $this->createWorkOrder(['lines' => [
            ['e_call_proposal_line_id' => $this->firstHalfLine->id, 'quantity' => '0.5'],
            ['e_call_proposal_line_id' => $this->secondHalfLine->id, 'quantity' => '0.5'],
        ]])
            ->assertCreated()
            ->assertJsonPath('data.lines.0.line_total', '0.50500')
            ->assertJsonPath('data.lines.1.line_total', '0.50500')
            ->assertJsonPath('data.estimated_amount_exact', '1.01000')
            ->assertJsonPath('data.estimated_amount', '1.01');

        $this->assertSame(101, WorkOrder::query()->sole()->estimated_amount_centavos, 'Rounding each line first would give 51 + 51 = 102.');
    }

    public function test_a_single_half_centavo_total_rounds_up(): void
    {
        $this->actingAs($this->requestor);

        $this->createWorkOrder(['lines' => [['e_call_proposal_line_id' => $this->firstHalfLine->id, 'quantity' => '0.5']]])
            ->assertCreated()
            ->assertJsonPath('data.estimated_amount', '0.51');
    }

    /**
     * @return array<string, array{ECallStatus}>
     */
    public static function statusesOtherThanApproved(): array
    {
        return [
            'for vendor assessment' => [ECallStatus::ForVendorAssessment],
            'for eic review' => [ECallStatus::ForEicReview],
            'returned' => [ECallStatus::Returned],
            'rejected' => [ECallStatus::Rejected],
            'closed' => [ECallStatus::Closed],
            'cancelled' => [ECallStatus::Cancelled],
        ];
    }

    #[DataProvider('statusesOtherThanApproved')]
    public function test_creating_from_an_e_call_that_is_not_approved_returns_422(ECallStatus $status): void
    {
        $this->eCall->update(['status' => $status]);
        $this->actingAs($this->requestor);

        $this->createWorkOrder($this->validPayload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['e_call']);

        $this->assertSame(0, WorkOrder::query()->count());
        $this->assertSame($status, $this->eCall->refresh()->status);
    }

    public function test_a_second_work_order_for_the_same_e_call_returns_422(): void
    {
        $this->createAsRequestor();
        $this->eCall->refresh()->update(['status' => ECallStatus::Approved]);

        $this->createWorkOrder($this->validPayload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['e_call']);

        $this->assertSame(1, WorkOrder::query()->count());
    }

    public function test_the_database_rejects_a_second_work_order_for_the_same_e_call(): void
    {
        $this->createAsRequestor();

        $this->expectException(UniqueConstraintViolationException::class);

        DB::table('work_orders')->insert([
            'e_call_id' => $this->eCall->id,
            'e_call_proposal_id' => $this->approvedProposal->id,
            'status' => 'open',
            'created_by_user_id' => $this->requestor->id,
            'estimated_amount_millicentavos' => 0,
            'estimated_amount_centavos' => 0,
        ]);
    }

    public function test_a_concurrent_create_that_wins_the_race_gives_422_not_a_server_error(): void
    {
        $this->actingAs($this->requestor);

        $inserted = false;
        DB::listen(function ($query) use (&$inserted): void {
            if ($inserted || ! str_contains($query->sql, 'from "work_orders"')) {
                return;
            }

            // Another request committed this E-Call's Work Order just after this one checked for it.
            $inserted = true;
            DB::table('work_orders')->insert([
                'e_call_id' => $this->eCall->id,
                'e_call_proposal_id' => $this->approvedProposal->id,
                'status' => 'open',
                'created_by_user_id' => $this->requestor->id,
                'estimated_amount_millicentavos' => 0,
                'estimated_amount_centavos' => 0,
            ]);
        });

        $this->createWorkOrder($this->validPayload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['e_call' => 'This E-Call already has a Work Order.']);

        $this->assertTrue($inserted);
        $this->assertSame(ECallStatus::Approved, $this->eCall->refresh()->status, 'The losing create rolls back: the E-Call is not closed by it.');
        // A real competing request commits on its own connection; this simulated one shares the test's connection, so
        // the losing create's rollback removes it too. What matters is the 422 instead of a 500 from the unique index.
    }

    /**
     * @return array<string, array{Closure(): User}>
     */
    public static function usersWhoAreNotTheRequestor(): array
    {
        return [
            'the current site eic' => [fn (): User => User::factory()->create(['employee_id' => 'P-EIC'])],
            'staff of the site vendor' => [fn (): User => User::factory()->vendorStaff(Vendor::query()->orderBy('id')->firstOrFail())->create()],
            'another employee' => [fn (): User => User::factory()->create(['employee_id' => 'P-OTHER'])],
            'a user with the requestors employee id but another account' => [fn (): User => User::factory()->create(['employee_id' => 'P-REQ'])],
        ];
    }

    /**
     * @param  Closure(): User  $makeUser
     */
    #[DataProvider('usersWhoAreNotTheRequestor')]
    public function test_only_the_requestor_can_create(Closure $makeUser): void
    {
        $this->actingAs($makeUser());

        $this->createWorkOrder($this->validPayload())->assertForbidden();

        $this->assertSame(0, WorkOrder::query()->count());
        $this->assertSame(ECallStatus::Approved, $this->eCall->refresh()->status);
    }

    public function test_a_non_requestor_gets_403_before_lines_are_validated(): void
    {
        $this->actingAs(User::factory()->create());

        $this->createWorkOrder(['lines' => []])->assertForbidden();
    }

    public function test_the_model_rechecks_the_requestor_inside_the_lock(): void
    {
        try {
            WorkOrder::createFromECall($this->eCall, User::factory()->create(), [['line' => $this->paintLine, 'quantity_thousandths' => 1_000]], []);
            $this->fail('Only the requestor may create the Work Order.');
        } catch (AuthorizationException) {
            // Expected.
        }

        $this->assertSame(0, WorkOrder::query()->count());
        $this->assertSame(ECallStatus::Approved, $this->eCall->refresh()->status);
    }

    public function test_a_line_from_an_earlier_boq_revision_returns_422(): void
    {
        $this->actingAs($this->requestor);
        $earlierLine = $this->eCall->proposals()->where('revision', 1)->sole()->lines()->sole();

        $this->createWorkOrder(['lines' => [['e_call_proposal_line_id' => $earlierLine->id, 'quantity' => '1']]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['lines.0.e_call_proposal_line_id']);
    }

    public function test_a_line_from_another_e_calls_boq_returns_422(): void
    {
        $otherECall = ECall::factory()->create(['vendor_id' => $this->siteVendor->id]);
        $otherProposal = $otherECall->proposals()->create($this->approvedProposal->only([
            'assessment_notes', 'fsr_disk', 'fsr_path', 'fsr_original_name', 'fsr_mime_type', 'fsr_size_bytes',
            'total_millicentavos', 'total_centavos', 'submitted_by_user_id', 'submitted_at',
        ]) + ['revision' => 1]);
        $otherLine = $otherProposal->lines()->create($this->paintLine->only([
            'price_list_item_id', 'code', 'description', 'type', 'unit_of_measure', 'quantity_thousandths', 'unit_price_centavos', 'line_total_millicentavos',
        ]));
        $this->actingAs($this->requestor);

        $this->createWorkOrder(['lines' => [['e_call_proposal_line_id' => $otherLine->id, 'quantity' => '1']]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['lines.0.e_call_proposal_line_id']);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidLines(): array
    {
        return [
            'no lines' => [['lines' => []], 'lines'],
            'missing lines' => [[], 'lines'],
            'unknown boq line' => [['lines' => [['e_call_proposal_line_id' => 999_999, 'quantity' => '1']]], 'lines.0.e_call_proposal_line_id'],
            'missing boq line' => [['lines' => [['quantity' => '1']]], 'lines.0.e_call_proposal_line_id'],
            'zero quantity' => [['lines' => [['e_call_proposal_line_id' => 'PAINT', 'quantity' => '0']]], 'lines.0.quantity'],
            'negative quantity' => [['lines' => [['e_call_proposal_line_id' => 'PAINT', 'quantity' => '-1']]], 'lines.0.quantity'],
            'more than the boq quantity' => [['lines' => [['e_call_proposal_line_id' => 'PAINT', 'quantity' => '2.501']]], 'lines.0.quantity'],
            'four decimals' => [['lines' => [['e_call_proposal_line_id' => 'PAINT', 'quantity' => '1.2345']]], 'lines.0.quantity'],
            'missing quantity' => [['lines' => [['e_call_proposal_line_id' => 'PAINT']]], 'lines.0.quantity'],
            'the same boq line twice' => [['lines' => [
                ['e_call_proposal_line_id' => 'PAINT', 'quantity' => '1'],
                ['e_call_proposal_line_id' => 'PAINT', 'quantity' => '1'],
            ]], 'lines.1.e_call_proposal_line_id'],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload  'PAINT' stands for the approved paint line's ID.
     */
    #[DataProvider('invalidLines')]
    public function test_invalid_lines_return_422(array $payload, string $field): void
    {
        array_walk_recursive($payload, function (mixed &$value): void {
            $value = $value === 'PAINT' ? $this->paintLine->id : $value;
        });
        $this->actingAs($this->requestor);

        $this->createWorkOrder($payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field]);

        $this->assertSame(0, WorkOrder::query()->count());
        $this->assertSame(ECallStatus::Approved, $this->eCall->refresh()->status);
    }

    public function test_the_quantity_limit_names_the_boq_quantity(): void
    {
        $this->actingAs($this->requestor);

        $response = $this->createWorkOrder(['lines' => [['e_call_proposal_line_id' => $this->paintLine->id, 'quantity' => '3']]]);

        $response->assertUnprocessable();
        $this->assertStringContainsString('2.500', $response->json('errors')['lines.0.quantity'][0]);
    }

    public function test_attachments_are_stored(): void
    {
        $this->actingAs($this->requestor);

        $this->createWorkOrder($this->validPayload(['attachments' => [UploadedFile::fake()->create('scope.pdf', 50, 'application/pdf')]]))
            ->assertCreated()
            ->assertJsonCount(1, 'data.attachments')
            ->assertJsonPath('data.attachments.0.original_name', 'scope.pdf');

        $attachment = WorkOrder::query()->sole()->attachments()->sole();
        Storage::disk(config('pss.e_call_attachments.disk'))->assertExists($attachment->path);
    }

    public function test_a_disallowed_attachment_returns_422_and_creates_nothing(): void
    {
        $this->actingAs($this->requestor);

        $this->createWorkOrder($this->validPayload(['attachments' => [UploadedFile::fake()->create('virus.exe', 10, 'application/x-msdownload')]]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['attachments.0']);

        $this->assertSame(0, WorkOrder::query()->count());
        $this->assertEmpty(Storage::disk(config('pss.e_call_attachments.disk'))->allFiles());
    }

    /**
     * @return array<string, array{Closure(): User}>
     */
    public static function usersWhoCanView(): array
    {
        return [
            'the requestor' => [fn (): User => User::query()->where('employee_id', 'P-REQ')->firstOrFail()],
            'a non-vendor user' => [fn (): User => User::factory()->create()],
            'staff of the site vendor' => [fn (): User => User::factory()->vendorStaff(Vendor::query()->orderBy('id')->firstOrFail())->create()],
        ];
    }

    /**
     * @param  Closure(): User  $makeUser
     */
    #[DataProvider('usersWhoCanView')]
    public function test_the_work_order_is_visible_like_its_e_call(Closure $makeUser): void
    {
        $workOrder = $this->createAsRequestor();
        $this->actingAs($makeUser());

        $this->getJson("/api/work-orders/{$workOrder->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $workOrder->id)
            ->assertJsonPath('data.estimated_amount', '311.96');
    }

    public function test_staff_of_another_vendor_cannot_view_the_work_order(): void
    {
        $workOrder = $this->createAsRequestor();
        $this->actingAs(User::factory()->vendorStaff()->create());

        $this->getJson("/api/work-orders/{$workOrder->id}")->assertForbidden();
    }

    public function test_an_unknown_work_order_returns_404(): void
    {
        $this->actingAs($this->requestor);

        $this->getJson('/api/work-orders/999999')->assertNotFound();
    }

    private function patchWorkOrder(WorkOrder $workOrder, array $payload): TestResponse
    {
        return $this->post("/api/work-orders/{$workOrder->id}", ['_method' => 'PATCH', ...$payload], ['Accept' => 'application/json']);
    }

    public function test_the_creator_replaces_the_lines_while_open_and_totals_are_recomputed(): void
    {
        $workOrder = $this->createAsRequestor();

        $this->patchWorkOrder($workOrder, [
            'lines' => [['e_call_proposal_line_id' => $this->firstHalfLine->id, 'quantity' => '0.5']],
            'attachments' => [UploadedFile::fake()->create('revised-scope.pdf', 20, 'application/pdf')],
        ])
            ->assertOk()
            ->assertJsonCount(1, 'data.lines')
            ->assertJsonPath('data.lines.0.e_call_proposal_line_id', $this->firstHalfLine->id)
            ->assertJsonPath('data.estimated_amount_exact', '0.50500')
            ->assertJsonPath('data.estimated_amount', '0.51')
            ->assertJsonCount(1, 'data.attachments')
            ->assertJsonPath('data.status', 'open');

        $workOrder->refresh();
        $this->assertSame(1, $workOrder->lines()->count());
        $this->assertSame(51, $workOrder->estimated_amount_centavos);
    }

    public function test_patch_validates_lines_like_create(): void
    {
        $workOrder = $this->createAsRequestor();

        $this->patchWorkOrder($workOrder, ['lines' => [['e_call_proposal_line_id' => $this->paintLine->id, 'quantity' => '9']]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['lines.0.quantity']);

        $this->assertSame(2, $workOrder->lines()->count(), 'A rejected PATCH changes nothing.');
    }

    /**
     * @return array<string, array{Closure(): User}>
     */
    public static function usersWhoAreNotTheCreator(): array
    {
        return [
            'the current site eic' => [fn (): User => User::factory()->create(['employee_id' => 'P-EIC'])],
            'staff of the site vendor' => [fn (): User => User::factory()->vendorStaff(Vendor::query()->orderBy('id')->firstOrFail())->create()],
            'another employee' => [fn (): User => User::factory()->create(['employee_id' => 'P-OTHER'])],
        ];
    }

    /**
     * @param  Closure(): User  $makeUser
     */
    #[DataProvider('usersWhoAreNotTheCreator')]
    public function test_only_the_creator_can_patch(Closure $makeUser): void
    {
        $workOrder = $this->createAsRequestor();
        $this->actingAs($makeUser());

        $this->patchWorkOrder($workOrder, $this->validPayload())->assertForbidden();
    }

    public function test_patch_after_the_work_order_left_open_returns_422(): void
    {
        $workOrder = $this->createAsRequestor();
        // A status a later issue adds (19: submission); stored raw because this slice's enum only has Open.
        DB::table('work_orders')->where('id', $workOrder->id)->update(['status' => 'submitted']);

        $this->patchWorkOrder($workOrder, $this->validPayload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['work_order']);

        $this->assertSame(2, DB::table('work_order_lines')->where('work_order_id', $workOrder->id)->count());
    }

    public function test_a_new_work_order_starts_open(): void
    {
        $this->assertSame(WorkOrderStatus::Open, $this->createAsRequestor()->status);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function allEndpoints(): array
    {
        return [
            'create' => ['post', 'e-calls/{eCall}/work-order'],
            'show' => ['get', 'work-orders/1'],
            'patch' => ['patch', 'work-orders/1'],
        ];
    }

    #[DataProvider('allEndpoints')]
    public function test_every_endpoint_returns_401_when_unauthenticated(string $method, string $path): void
    {
        $this->json($method, '/api/'.str_replace('{eCall}', (string) $this->eCall->id, $path), $this->validPayload())->assertUnauthorized();
    }
}
