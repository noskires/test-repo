<?php

namespace Tests\Feature;

use App\Enums\ECallStatus;
use App\Enums\PriceListItemType;
use App\Enums\WorkOrderStatus;
use App\Models\ApprovalRecord;
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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WorkOrderCompletionApiTest extends TestCase
{
    use RefreshDatabase;

    private Vendor $siteVendor;

    private User $requestor;

    private WorkOrder $workOrder;

    private WorkOrderLine $paintLine;

    private WorkOrderLine $laborLine;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-23 14:00:00');
        Storage::fake(config('pss.e_call_attachments.disk'));

        $this->siteVendor = Vendor::factory()->create();
        SiteSetting::factory()->create(['site_id' => 'S-1', 'vendor_id' => $this->siteVendor->id, 'eic_employee_id' => 'P-EIC']);
        $this->requestor = User::factory()->create(['employee_id' => 'P-REQ']);
        $this->workOrder = $this->workOrderFor($this->siteVendor, $this->requestor);
        [$this->paintLine, $this->laborLine] = $this->workOrder->lines->all();
        $this->setStatus(WorkOrderStatus::ForImplementation);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
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
        foreach ([['Paint, 4L', 'gal', 2_500, 12_345], ['Repair labor', 'hour', 4_000, 50_000]] as [$description, $unit, $quantity, $price]) {
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

    private function setStatus(WorkOrderStatus $status, ?WorkOrder $workOrder = null): void
    {
        DB::table('work_orders')->where('id', ($workOrder ?? $this->workOrder)->id)->update(['status' => $status->value]);
    }

    private function vendorStaff(?Vendor $vendor = null): User
    {
        return User::factory()->vendorStaff($vendor ?? $this->siteVendor)->create();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'lines' => [
                ['work_order_line_id' => $this->paintLine->id, 'actual_quantity' => '2.75'],
                ['work_order_line_id' => $this->laborLine->id, 'actual_quantity' => '0'],
            ],
            'documents' => [UploadedFile::fake()->create('completion-report.pdf', 100, 'application/pdf')],
            'photos' => [UploadedFile::fake()->image('after.jpg')],
            'notes' => 'Done. Labor was covered by the warranty.',
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function declare(array $payload, ?WorkOrder $workOrder = null): TestResponse
    {
        return $this->post('/api/work-orders/'.($workOrder ?? $this->workOrder)->id.'/completion', $payload, ['Accept' => 'application/json']);
    }

    public function test_site_vendor_staff_declare_completion_and_the_work_order_is_implemented(): void
    {
        $staff = $this->vendorStaff();
        $this->actingAs($staff);

        $this->declare($this->validPayload())
            ->assertCreated()
            ->assertJsonPath('data.revision', 1)
            ->assertJsonPath('data.is_current', true)
            ->assertJsonPath('data.work_order_id', $this->workOrder->id)
            ->assertJsonPath('data.notes', 'Done. Labor was covered by the warranty.')
            ->assertJsonPath('data.submitted_by_user_id', $staff->id)
            ->assertJsonPath('data.submitted_at', '2026-11-23T06:00:00.000000Z')
            ->assertJsonPath('data.lines.0.work_order_line_id', $this->paintLine->id)
            ->assertJsonPath('data.lines.0.description', 'Paint, 4L')
            ->assertJsonPath('data.lines.0.approved_quantity', '2.500')
            ->assertJsonPath('data.lines.0.actual_quantity', '2.750')
            ->assertJsonPath('data.lines.1.actual_quantity', '0.000')
            ->assertJsonCount(1, 'data.documents')
            ->assertJsonPath('data.documents.0.original_name', 'completion-report.pdf')
            ->assertJsonCount(1, 'data.photos');

        $this->assertSame(WorkOrderStatus::Implemented, $this->workOrder->refresh()->status);

        $completion = WorkOrderCompletion::query()->with(['lines', 'files'])->sole();
        $this->assertSame([2_750, 0], $completion->lines->sortBy('work_order_line_id')->pluck('actual_quantity_thousandths')->values()->all());
        $this->assertCount(2, $completion->files);
        foreach ($completion->files as $file) {
            Storage::disk(config('pss.e_call_attachments.disk'))->assertExists($file->path);
        }
        $this->assertSame(0, ApprovalRecord::query()->count(), 'Declaring completion is not an approval action.');
    }

    public function test_photos_and_notes_are_optional(): void
    {
        $this->actingAs($this->vendorStaff());
        $payload = $this->validPayload();
        unset($payload['photos'], $payload['notes']);

        $this->declare($payload)->assertCreated()->assertJsonPath('data.notes', null)->assertJsonCount(0, 'data.photos');
    }

    /**
     * @return array<string, array{WorkOrderStatus}>
     */
    public static function statusesOtherThanForImplementation(): array
    {
        return [
            'open' => [WorkOrderStatus::Open],
            'for lvl1 endorsement' => [WorkOrderStatus::ForLvl1Endorsement],
            'for lvl2 endorsement' => [WorkOrderStatus::ForLvl2Endorsement],
            'implemented' => [WorkOrderStatus::Implemented],
            'rejected' => [WorkOrderStatus::Rejected],
        ];
    }

    #[DataProvider('statusesOtherThanForImplementation')]
    public function test_declaring_outside_for_implementation_returns_422_before_the_payload_is_checked(WorkOrderStatus $status): void
    {
        $this->setStatus($status);
        $this->actingAs($this->vendorStaff());

        $response = $this->declare([])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['work_order']);

        $this->assertSame(['work_order'], array_keys($response->json('errors')), 'The status is reported before any payload error.');
        $this->assertSame($status, $this->workOrder->refresh()->status);
        $this->assertSame(0, WorkOrderCompletion::query()->count());
    }

    #[DataProvider('statusesOtherThanForImplementation')]
    public function test_a_user_who_cannot_view_the_work_order_gets_a_plain_403_whatever_its_status(WorkOrderStatus $status): void
    {
        $this->setStatus($status);
        $this->actingAs($this->vendorStaff(Vendor::factory()->create()));

        $this->declare($this->validPayload())->assertForbidden();
        $this->declare([])->assertForbidden();
    }

    /**
     * @return array<string, array{Closure(): User}>
     */
    public static function viewersWhoAreNotTheSiteVendor(): array
    {
        return [
            'the requestor' => [fn (): User => User::query()->where('employee_id', 'P-REQ')->firstOrFail()],
            'the site eic' => [fn (): User => User::factory()->create(['employee_id' => 'P-EIC'])],
            'another internal user' => [fn (): User => User::factory()->create()],
        ];
    }

    /**
     * @param  Closure(): User  $makeUser
     */
    #[DataProvider('viewersWhoAreNotTheSiteVendor')]
    public function test_a_viewer_who_is_not_the_site_vendors_staff_gets_403(Closure $makeUser): void
    {
        $this->actingAs($makeUser());

        $this->declare($this->validPayload())->assertForbidden();

        $this->assertSame(WorkOrderStatus::ForImplementation, $this->workOrder->refresh()->status);
    }

    public function test_staff_of_the_vendor_captured_on_the_e_call_act_even_if_the_site_vendor_changed(): void
    {
        SiteSetting::query()->where('site_id', 'S-1')->update(['vendor_id' => Vendor::factory()->create()->id]);
        $this->actingAs($this->vendorStaff());

        $this->declare($this->validPayload())->assertCreated();
    }

    /**
     * @return array<string, array{Closure(self): array<string, mixed>, string}>
     */
    public static function invalidLines(): array
    {
        return [
            'a missing line' => [fn (self $t): array => ['lines' => [['work_order_line_id' => $t->paintLine->id, 'actual_quantity' => '1']]], 'lines'],
            'no lines' => [fn (self $t): array => ['lines' => []], 'lines'],
            'a duplicate line' => [fn (self $t): array => ['lines' => [
                ['work_order_line_id' => $t->paintLine->id, 'actual_quantity' => '1'],
                ['work_order_line_id' => $t->paintLine->id, 'actual_quantity' => '1'],
            ]], 'lines.1.work_order_line_id'],
            'a line from another work order' => [fn (self $t): array => ['lines' => [
                ['work_order_line_id' => $t->paintLine->id, 'actual_quantity' => '1'],
                ['work_order_line_id' => $t->workOrderFor($t->siteVendor, User::factory()->create(['employee_id' => 'P-R2']))->lines->first()->id, 'actual_quantity' => '1'],
            ]], 'lines.1.work_order_line_id'],
            'a negative quantity' => [fn (self $t): array => ['lines' => [
                ['work_order_line_id' => $t->paintLine->id, 'actual_quantity' => '-1'],
                ['work_order_line_id' => $t->laborLine->id, 'actual_quantity' => '1'],
            ]], 'lines.0.actual_quantity'],
            'four decimals' => [fn (self $t): array => ['lines' => [
                ['work_order_line_id' => $t->paintLine->id, 'actual_quantity' => '1.2345'],
                ['work_order_line_id' => $t->laborLine->id, 'actual_quantity' => '1'],
            ]], 'lines.0.actual_quantity'],
            'a missing quantity' => [fn (self $t): array => ['lines' => [
                ['work_order_line_id' => $t->paintLine->id],
                ['work_order_line_id' => $t->laborLine->id, 'actual_quantity' => '1'],
            ]], 'lines.0.actual_quantity'],
        ];
    }

    /**
     * @param  Closure(self): array<string, mixed>  $makePayload
     */
    #[DataProvider('invalidLines')]
    public function test_invalid_lines_return_422(Closure $makePayload, string $field): void
    {
        $this->actingAs($this->vendorStaff());

        $this->declare($this->validPayload($makePayload($this)))
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field]);

        $this->assertSame(WorkOrderStatus::ForImplementation, $this->workOrder->refresh()->status);
        $this->assertSame(0, WorkOrderCompletion::query()->count());
        $this->assertEmpty(Storage::disk(config('pss.e_call_attachments.disk'))->allFiles());
    }

    public function test_a_missing_line_error_names_the_line(): void
    {
        $this->actingAs($this->vendorStaff());

        $response = $this->declare($this->validPayload(['lines' => [['work_order_line_id' => $this->paintLine->id, 'actual_quantity' => '1']]]));

        $this->assertStringContainsString('Repair labor', $response->json('errors')['lines'][0]);
    }

    public function test_an_actual_quantity_above_the_approved_quantity_is_accepted(): void
    {
        $this->actingAs($this->vendorStaff());

        $this->declare($this->validPayload(['lines' => [
            ['work_order_line_id' => $this->paintLine->id, 'actual_quantity' => '9999999.999'],
            ['work_order_line_id' => $this->laborLine->id, 'actual_quantity' => '4'],
        ]]))->assertCreated()->assertJsonPath('data.lines.0.actual_quantity', '9999999.999');
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidFiles(): array
    {
        return [
            'no documents' => [['documents' => []], 'documents'],
            'documents missing' => [['documents' => null], 'documents'],
            'a disallowed document' => [['documents' => [UploadedFile::fake()->create('virus.exe', 10, 'application/x-msdownload')]], 'documents.0'],
            'an oversized document' => [['documents' => [UploadedFile::fake()->create('big.pdf', 10241, 'application/pdf')]], 'documents.0'],
            'a photo that is not an image' => [['photos' => [UploadedFile::fake()->create('photo.pdf', 10, 'application/pdf')]], 'photos.0'],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    #[DataProvider('invalidFiles')]
    public function test_invalid_files_return_422(array $overrides, string $field): void
    {
        $this->actingAs($this->vendorStaff());

        $this->declare(array_filter($this->validPayload($overrides), fn (mixed $value): bool => $value !== null))
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field]);

        $this->assertSame(0, WorkOrderCompletion::query()->count());
    }

    public function test_a_resubmission_after_a_return_is_a_new_revision_and_keeps_the_earlier_one(): void
    {
        $this->actingAs($this->vendorStaff());
        $this->declare($this->validPayload())->assertCreated();

        // Issue 21's EIC Return sends the Work Order back to For Implementation.
        $this->setStatus(WorkOrderStatus::ForImplementation);

        $this->declare($this->validPayload([
            'lines' => [
                ['work_order_line_id' => $this->paintLine->id, 'actual_quantity' => '2.5'],
                ['work_order_line_id' => $this->laborLine->id, 'actual_quantity' => '3'],
            ],
            'documents' => [UploadedFile::fake()->create('completion-report-v2.pdf', 50, 'application/pdf')],
            'notes' => 'Corrected the labor hours.',
        ]))
            ->assertCreated()
            ->assertJsonPath('data.revision', 2);

        $this->assertSame(WorkOrderStatus::Implemented, $this->workOrder->refresh()->status);

        $this->getJson("/api/work-orders/{$this->workOrder->id}/completions")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.revision', 2)
            ->assertJsonPath('data.0.is_current', true)
            ->assertJsonPath('data.0.lines.1.actual_quantity', '3.000')
            ->assertJsonPath('data.0.documents.0.original_name', 'completion-report-v2.pdf')
            ->assertJsonPath('data.1.revision', 1)
            ->assertJsonPath('data.1.is_current', false)
            ->assertJsonPath('data.1.notes', 'Done. Labor was covered by the warranty.')
            ->assertJsonPath('data.1.lines.1.actual_quantity', '0.000')
            ->assertJsonPath('data.1.documents.0.original_name', 'completion-report.pdf');

        foreach (WorkOrderCompletion::query()->with('files')->get()->flatMap->files as $file) {
            Storage::disk(config('pss.e_call_attachments.disk'))->assertExists($file->path);
        }
    }

    public function test_the_model_rechecks_the_vendor_and_the_status_inside_the_lock(): void
    {
        $lines = [['line' => $this->paintLine, 'quantity_thousandths' => 1_000], ['line' => $this->laborLine, 'quantity_thousandths' => 1_000]];
        $document = [UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf')];

        try {
            WorkOrderCompletion::submit($this->workOrder, $this->vendorStaff(Vendor::factory()->create()), null, $lines, $document, []);
            $this->fail('Only the Site Vendor\'s staff may declare completion.');
        } catch (AuthorizationException) {
            // Expected.
        }

        $this->setStatus(WorkOrderStatus::Implemented);

        try {
            WorkOrderCompletion::submit($this->workOrder, $this->vendorStaff(), null, $lines, $document, []);
            $this->fail('Completion can only be declared while For Implementation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('work_order', $exception->errors());
        }

        $this->assertSame(0, WorkOrderCompletion::query()->count());
        $this->assertEmpty(Storage::disk(config('pss.e_call_attachments.disk'))->allFiles());
    }

    public function test_the_completion_history_is_hidden_from_users_who_cannot_view_the_work_order(): void
    {
        $this->actingAs($this->vendorStaff());
        $this->declare($this->validPayload())->assertCreated();

        $this->actingAs($this->vendorStaff(Vendor::factory()->create()));
        $this->getJson("/api/work-orders/{$this->workOrder->id}/completions")->assertForbidden();

        $this->actingAs($this->requestor);
        $this->getJson("/api/work-orders/{$this->workOrder->id}/completions")->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_vendor_staff_list_only_their_vendors_work_orders(): void
    {
        $otherVendor = Vendor::factory()->create();
        $othersWorkOrder = $this->workOrderFor($otherVendor, User::factory()->create(['employee_id' => 'P-R3']));
        $alsoMine = $this->workOrderFor($this->siteVendor, User::factory()->create(['employee_id' => 'P-R4']));

        $this->actingAs($this->vendorStaff());
        $ids = collect($this->getJson('/api/vendor/work-orders')->assertOk()->json('data'))->pluck('id')->sort()->values()->all();
        $this->assertSame([$this->workOrder->id, $alsoMine->id], $ids);

        $this->actingAs($this->vendorStaff($otherVendor));
        $this->getJson('/api/vendor/work-orders')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $othersWorkOrder->id);
    }

    public function test_the_vendor_list_shows_the_work_order_details(): void
    {
        $this->actingAs($this->vendorStaff());

        $this->getJson('/api/vendor/work-orders')
            ->assertOk()
            ->assertJsonPath('data.0.status', 'for_implementation')
            ->assertJsonCount(2, 'data.0.lines');
    }

    public function test_a_non_vendor_user_cannot_use_the_vendor_list(): void
    {
        $this->actingAs($this->requestor);

        $this->getJson('/api/vendor/work-orders')->assertForbidden();
    }

    public function test_the_implemented_status_exists(): void
    {
        $this->assertSame('implemented', WorkOrderStatus::Implemented->value);
        $this->assertNull(WorkOrderStatus::Implemented->endorsementLevel());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function allEndpoints(): array
    {
        return [
            'declare completion' => ['post', 'work-orders/{wo}/completion'],
            'completion history' => ['get', 'work-orders/{wo}/completions'],
            'vendor list' => ['get', 'vendor/work-orders'],
        ];
    }

    #[DataProvider('allEndpoints')]
    public function test_every_endpoint_returns_401_when_unauthenticated(string $method, string $path): void
    {
        $this->json($method, '/api/'.str_replace('{wo}', (string) $this->workOrder->id, $path))->assertUnauthorized();
    }
}
