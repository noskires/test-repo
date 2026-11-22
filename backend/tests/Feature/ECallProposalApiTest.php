<?php

namespace Tests\Feature;

use App\Enums\ECallStatus;
use App\Models\ECall;
use App\Models\ECallProposal;
use App\Models\PriceListItem;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ECallProposalApiTest extends TestCase
{
    use RefreshDatabase;

    private Vendor $siteVendor;

    private ECall $eCall;

    private PriceListItem $paint;

    private PriceListItem $labor;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('pss.e_call_attachments.disk'));

        $this->siteVendor = Vendor::factory()->create();
        $this->eCall = ECall::factory()->create(['vendor_id' => $this->siteVendor->id]);
        $this->paint = PriceListItem::factory()->create([
            'vendor_id' => $this->siteVendor->id,
            'description' => 'Paint, 4L',
            'unit_of_measure' => 'gal',
            'unit_price_centavos' => 12_345,
        ]);
        $this->labor = PriceListItem::factory()->service()->create([
            'vendor_id' => $this->siteVendor->id,
            'unit_price_centavos' => 1_001,
        ]);
    }

    private function actingAsSiteVendorStaff(): User
    {
        $user = User::factory()->vendorStaff($this->siteVendor)->create();
        $this->actingAs($user);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'fsr' => UploadedFile::fake()->create('fsr.pdf', 120, 'application/pdf'),
            'assessment_notes' => 'Fence posts are rotten. Replace six posts and repaint.',
            'lines' => [
                ['price_list_item_id' => $this->paint->id, 'quantity' => '2.5'],
                ['price_list_item_id' => $this->labor->id, 'quantity' => '0.333'],
            ],
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function submit(array $payload, ?ECall $eCall = null): TestResponse
    {
        $eCall ??= $this->eCall;

        return $this->post("/api/e-calls/{$eCall->id}/proposal", $payload, ['Accept' => 'application/json']);
    }

    public function test_site_vendor_staff_submit_an_fsr_and_boq(): void
    {
        $user = $this->actingAsSiteVendorStaff();

        $this->submit($this->validPayload())
            ->assertCreated()
            ->assertJsonPath('data.e_call_id', $this->eCall->id)
            ->assertJsonPath('data.revision', 1)
            ->assertJsonPath('data.assessment_notes', 'Fence posts are rotten. Replace six posts and repaint.')
            ->assertJsonPath('data.fsr.original_name', 'fsr.pdf')
            ->assertJsonPath('data.submitted_by_user_id', $user->id)
            ->assertJsonPath('data.lines.0.price_list_item_id', $this->paint->id)
            ->assertJsonPath('data.lines.0.description', 'Paint, 4L')
            ->assertJsonPath('data.lines.0.unit_of_measure', 'gal')
            ->assertJsonPath('data.lines.0.quantity', '2.500')
            ->assertJsonPath('data.lines.0.unit_price', '123.45')
            ->assertJsonPath('data.lines.0.line_total', '308.62500')
            ->assertJsonPath('data.lines.1.quantity', '0.333')
            ->assertJsonPath('data.lines.1.unit_price', '10.01')
            ->assertJsonPath('data.lines.1.line_total', '3.33333')
            ->assertJsonPath('data.total', '311.95833')
            ->assertJsonPath('data.total_rounded', '311.96');

        $proposal = ECallProposal::query()->with('lines')->sole();
        $this->assertSame(31_196, $proposal->total_centavos);
        $this->assertSame(1, $proposal->revision);
        $this->assertSame($user->id, $proposal->submitted_by_user_id);
        $this->assertNotNull($proposal->submitted_at);
        $this->assertSame(31_195_833, $proposal->total_millicentavos);
        $this->assertSame([2_500, 333], $proposal->lines->pluck('quantity_thousandths')->all());
        $this->assertSame([12_345, 1_001], $proposal->lines->pluck('unit_price_centavos')->all());
        $this->assertSame([30_862_500, 333_333], $proposal->lines->pluck('line_total_millicentavos')->all());
        Storage::disk(config('pss.e_call_attachments.disk'))->assertExists($proposal->fsr_path);

        $this->assertSame(ECallStatus::ForEicReview, $this->eCall->refresh()->status);
    }

    public function test_a_numeric_quantity_is_parsed_and_multiplied_exactly(): void
    {
        $this->actingAsSiteVendorStaff();

        $this->submit($this->validPayload(['lines' => [['price_list_item_id' => $this->paint->id, 'quantity' => 1.005]]]))
            ->assertCreated()
            ->assertJsonPath('data.lines.0.quantity', '1.005')
            ->assertJsonPath('data.lines.0.line_total', '124.06725')
            ->assertJsonPath('data.total', '124.06725')
            ->assertJsonPath('data.total_rounded', '124.07');
    }

    public function test_only_the_boq_total_is_rounded_half_up_and_line_totals_stay_exact(): void
    {
        $this->actingAsSiteVendorStaff();
        $halfCentavoItem = PriceListItem::factory()->create(['vendor_id' => $this->siteVendor->id, 'unit_price_centavos' => 1]);
        $justBelowHalfItem = PriceListItem::factory()->create(['vendor_id' => $this->siteVendor->id, 'unit_price_centavos' => 499]);

        $this->submit($this->validPayload(['lines' => [['price_list_item_id' => $halfCentavoItem->id, 'quantity' => '0.5']]]))
            ->assertCreated()
            ->assertJsonPath('data.lines.0.line_total', '0.00500')
            ->assertJsonPath('data.total', '0.00500')
            ->assertJsonPath('data.total_rounded', '0.01');

        $this->eCall->update(['status' => ECallStatus::Returned]);

        $this->submit($this->validPayload(['lines' => [['price_list_item_id' => $justBelowHalfItem->id, 'quantity' => '0.001']]]))
            ->assertCreated()
            ->assertJsonPath('data.lines.0.line_total', '0.00499')
            ->assertJsonPath('data.total', '0.00499')
            ->assertJsonPath('data.total_rounded', '0.00');
    }

    public function test_a_single_half_centavo_line_total_rounds_up(): void
    {
        $this->actingAsSiteVendorStaff();
        $item = PriceListItem::factory()->create(['vendor_id' => $this->siteVendor->id, 'unit_price_centavos' => 101]);

        $this->submit($this->validPayload(['lines' => [['price_list_item_id' => $item->id, 'quantity' => '0.5']]]))
            ->assertCreated()
            ->assertJsonPath('data.lines.0.line_total', '0.50500')
            ->assertJsonPath('data.total', '0.50500')
            ->assertJsonPath('data.total_rounded', '0.51');

        $this->assertSame(51, ECallProposal::query()->sole()->total_centavos);
    }

    public function test_two_half_centavo_lines_sum_exactly_before_rounding_instead_of_rounding_each_line(): void
    {
        $this->actingAsSiteVendorStaff();
        [$first, $second] = PriceListItem::factory()->count(2)->create(['vendor_id' => $this->siteVendor->id, 'unit_price_centavos' => 101]);

        $this->submit($this->validPayload(['lines' => [
            ['price_list_item_id' => $first->id, 'quantity' => '0.5'],
            ['price_list_item_id' => $second->id, 'quantity' => '0.5'],
        ]]))
            ->assertCreated()
            ->assertJsonPath('data.lines.0.line_total', '0.50500')
            ->assertJsonPath('data.lines.1.line_total', '0.50500')
            ->assertJsonPath('data.total', '1.01000')
            ->assertJsonPath('data.total_rounded', '1.01');

        $this->assertSame(101, ECallProposal::query()->sole()->total_centavos, 'Rounding each line first would give 51 + 51 = 102.');
    }

    public function test_line_totals_are_summed_exactly_before_the_total_is_rounded(): void
    {
        $this->actingAsSiteVendorStaff();
        [$first, $second] = PriceListItem::factory()->count(2)->create(['vendor_id' => $this->siteVendor->id, 'unit_price_centavos' => 1]);

        $this->submit($this->validPayload(['lines' => [
            ['price_list_item_id' => $first->id, 'quantity' => '0.4'],
            ['price_list_item_id' => $second->id, 'quantity' => '0.4'],
        ]]))
            ->assertCreated()
            ->assertJsonPath('data.lines.0.line_total', '0.00400')
            ->assertJsonPath('data.lines.1.line_total', '0.00400')
            ->assertJsonPath('data.total', '0.00800')
            ->assertJsonPath('data.total_rounded', '0.01');
    }

    public function test_changing_a_price_list_price_after_submission_does_not_change_the_boq(): void
    {
        $this->actingAsSiteVendorStaff();
        $this->submit($this->validPayload())->assertCreated();

        $this->paint->update(['unit_price_centavos' => 99_999, 'description' => 'Paint, renamed']);

        $this->getJson("/api/e-calls/{$this->eCall->id}/proposals")
            ->assertOk()
            ->assertJsonPath('data.0.lines.0.unit_price', '123.45')
            ->assertJsonPath('data.0.lines.0.description', 'Paint, 4L')
            ->assertJsonPath('data.0.lines.0.line_total', '308.62500')
            ->assertJsonPath('data.0.total', '311.95833');
    }

    public function test_resubmitting_after_returned_creates_a_new_revision_and_keeps_the_old_one(): void
    {
        $this->actingAsSiteVendorStaff();
        $this->submit($this->validPayload())->assertCreated();

        $this->eCall->update(['status' => ECallStatus::Returned]);

        $this->submit($this->validPayload([
            'fsr' => UploadedFile::fake()->create('fsr-v2.pdf', 80, 'application/pdf'),
            'assessment_notes' => 'Revised: four posts only.',
            'lines' => [['price_list_item_id' => $this->paint->id, 'quantity' => '4']],
        ]))
            ->assertCreated()
            ->assertJsonPath('data.revision', 2)
            ->assertJsonPath('data.total', '493.80000');

        $this->assertSame(ECallStatus::ForEicReview, $this->eCall->refresh()->status);
        $this->assertSame(2, ECallProposal::query()->count());

        $this->getJson("/api/e-calls/{$this->eCall->id}/proposals")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.revision', 2)
            ->assertJsonPath('data.0.fsr.original_name', 'fsr-v2.pdf')
            ->assertJsonPath('data.1.revision', 1)
            ->assertJsonPath('data.1.assessment_notes', 'Fence posts are rotten. Replace six posts and repaint.')
            ->assertJsonPath('data.1.fsr.original_name', 'fsr.pdf')
            ->assertJsonCount(2, 'data.1.lines')
            ->assertJsonPath('data.1.total', '311.95833');

        foreach (ECallProposal::all() as $proposal) {
            Storage::disk(config('pss.e_call_attachments.disk'))->assertExists($proposal->fsr_path);
        }
    }

    /**
     * @return array<string, array{\Closure(Vendor): array<string, mixed>}>
     */
    public static function usersWhoAreNotSiteVendorStaff(): array
    {
        return [
            'staff of another vendor' => [fn (Vendor $siteVendor): array => ['vendor_id' => Vendor::factory()->create()->id]],
            'a non-vendor user such as the requestor or eic' => [fn (Vendor $siteVendor): array => ['employee_id' => 'P-1']],
        ];
    }

    /**
     * @param  \Closure(Vendor): array<string, mixed>  $attributes
     */
    #[DataProvider('usersWhoAreNotSiteVendorStaff')]
    public function test_only_site_vendor_staff_can_submit(\Closure $attributes): void
    {
        $this->actingAs(User::factory()->create($attributes($this->siteVendor)));

        $this->submit($this->validPayload())->assertForbidden();

        $this->assertDatabaseCount('e_call_proposals', 0);
        $this->assertSame(ECallStatus::ForVendorAssessment, $this->eCall->refresh()->status);
    }

    public function test_the_requestor_cannot_submit(): void
    {
        $this->actingAs($this->eCall->requestor);

        $this->submit($this->validPayload())->assertForbidden();
    }

    /**
     * @return array<string, array{ECallStatus}>
     */
    public static function statusesThatDoNotAcceptAProposal(): array
    {
        return [
            'for eic review' => [ECallStatus::ForEicReview],
            'approved' => [ECallStatus::Approved],
            'closed' => [ECallStatus::Closed],
            'rejected' => [ECallStatus::Rejected],
            'cancelled' => [ECallStatus::Cancelled],
        ];
    }

    #[DataProvider('statusesThatDoNotAcceptAProposal')]
    public function test_submitting_in_any_other_status_returns_422(ECallStatus $status): void
    {
        $this->eCall->update(['status' => $status]);
        $this->actingAsSiteVendorStaff();

        $this->submit($this->validPayload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['e_call']);

        $this->assertDatabaseCount('e_call_proposals', 0);
        $this->assertSame($status, $this->eCall->refresh()->status);
    }

    public function test_an_item_from_another_vendor_returns_422(): void
    {
        $this->actingAsSiteVendorStaff();
        $otherVendorsItem = PriceListItem::factory()->create();

        $this->submit($this->validPayload(['lines' => [['price_list_item_id' => $otherVendorsItem->id, 'quantity' => '1']]]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['lines.0.price_list_item_id']);

        $this->assertDatabaseCount('e_call_proposals', 0);
    }

    public function test_an_inactive_item_returns_422(): void
    {
        $this->actingAsSiteVendorStaff();
        $inactiveItem = PriceListItem::factory()->inactive()->create(['vendor_id' => $this->siteVendor->id]);

        $this->submit($this->validPayload(['lines' => [['price_list_item_id' => $inactiveItem->id, 'quantity' => '1']]]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['lines.0.price_list_item_id']);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function invalidQuantities(): array
    {
        return [
            'zero' => ['0'],
            'zero with decimals' => ['0.000'],
            'negative' => ['-1'],
            'more than three decimal places' => ['1.2345'],
            'not a number' => ['abc'],
            'exponent' => ['1e2'],
            'missing' => [null],
            'too large' => ['10000000'],
        ];
    }

    #[DataProvider('invalidQuantities')]
    public function test_an_invalid_quantity_returns_422(mixed $quantity): void
    {
        $this->actingAsSiteVendorStaff();

        $this->submit($this->validPayload(['lines' => [['price_list_item_id' => $this->paint->id, 'quantity' => $quantity]]]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['lines.0.quantity']);

        $this->assertDatabaseCount('e_call_proposals', 0);
    }

    public function test_the_quantity_decimal_places_are_configurable(): void
    {
        config(['pss.boq.quantity_decimal_places' => 1]);
        $this->actingAsSiteVendorStaff();

        $this->submit($this->validPayload(['lines' => [['price_list_item_id' => $this->paint->id, 'quantity' => '1.25']]]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['lines.0.quantity']);

        $this->submit($this->validPayload(['lines' => [['price_list_item_id' => $this->paint->id, 'quantity' => '1.5']]]))
            ->assertCreated();
    }

    public function test_the_default_quantity_precision_is_three_decimal_places(): void
    {
        $this->assertSame(3, config('pss.boq.quantity_decimal_places'));
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidProposals(): array
    {
        return [
            'missing fsr' => [['fsr' => null], 'fsr'],
            'fsr that is not an allowed type' => [['fsr' => UploadedFile::fake()->create('fsr.exe', 10, 'application/x-msdownload')], 'fsr'],
            'fsr over the size limit' => [['fsr' => UploadedFile::fake()->create('fsr.pdf', 10241, 'application/pdf')], 'fsr'],
            'no lines' => [['lines' => []], 'lines'],
            'missing lines' => [['lines' => null], 'lines'],
            'missing assessment notes' => [['assessment_notes' => null], 'assessment_notes'],
            'missing item' => [['lines' => [['quantity' => '1']]], 'lines.0.price_list_item_id'],
            'unknown item' => [['lines' => [['price_list_item_id' => 999_999, 'quantity' => '1']]], 'lines.0.price_list_item_id'],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    #[DataProvider('invalidProposals')]
    public function test_an_invalid_proposal_returns_422(array $overrides, string $field): void
    {
        $this->actingAsSiteVendorStaff();

        $this->submit(array_filter($this->validPayload($overrides), fn (mixed $value): bool => $value !== null))
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field]);

        $this->assertDatabaseCount('e_call_proposals', 0);
        $this->assertEmpty(Storage::disk(config('pss.e_call_attachments.disk'))->allFiles());
    }

    public function test_the_same_item_twice_returns_422(): void
    {
        $this->actingAsSiteVendorStaff();

        $this->submit($this->validPayload(['lines' => [
            ['price_list_item_id' => $this->paint->id, 'quantity' => '1'],
            ['price_list_item_id' => $this->paint->id, 'quantity' => '2'],
        ]]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['lines.1.price_list_item_id']);
    }

    public function test_a_total_too_large_to_store_exactly_returns_422(): void
    {
        $this->actingAsSiteVendorStaff();
        $priceyItem = PriceListItem::factory()->create(['vendor_id' => $this->siteVendor->id, 'unit_price_centavos' => 99_999_999_999_999]);

        $this->submit($this->validPayload(['lines' => [['price_list_item_id' => $priceyItem->id, 'quantity' => '9999999.999']]]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['lines']);

        $this->assertDatabaseCount('e_call_proposals', 0);
    }

    public function test_submitting_for_an_unknown_e_call_returns_404(): void
    {
        $this->actingAsSiteVendorStaff();

        $this->post('/api/e-calls/999999/proposal', $this->validPayload(), ['Accept' => 'application/json'])->assertNotFound();
    }

    public function test_vendor_staff_of_another_vendor_cannot_list_the_proposals(): void
    {
        $this->actingAsSiteVendorStaff();
        $this->submit($this->validPayload())->assertCreated();

        $this->actingAs(User::factory()->vendorStaff()->create());

        $this->getJson("/api/e-calls/{$this->eCall->id}/proposals")->assertForbidden();
    }

    public function test_non_vendor_users_can_list_the_proposals(): void
    {
        $this->actingAsSiteVendorStaff();
        $this->submit($this->validPayload())->assertCreated();

        $this->actingAs(User::factory()->create());

        $this->getJson("/api/e-calls/{$this->eCall->id}/proposals")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function allEndpoints(): array
    {
        return [
            'submit' => ['post', 'proposal'],
            'list' => ['get', 'proposals'],
        ];
    }

    #[DataProvider('allEndpoints')]
    public function test_every_endpoint_returns_401_when_unauthenticated(string $method, string $path): void
    {
        $this->json($method, "/api/e-calls/{$this->eCall->id}/{$path}")->assertUnauthorized();

        $this->assertDatabaseCount('e_call_proposals', 0);
    }
}
