<?php

namespace Tests\Feature;

use App\Enums\ECallStatus;
use App\Enums\PmStatus;
use App\Enums\WorkOrderStatus;
use App\Models\ECall;
use App\Models\PmTask;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Dashboard operational counts (issue 27): current-state counts of open PM Tasks, E-Calls and Work Orders by status,
 * narrowed by site, equipment category and vendor.
 */
class DashboardApiTest extends TestCase
{
    use RefreshDatabase;

    private Vendor $vendor;

    private Vendor $otherVendor;

    private User $internal;

    protected function setUp(): void
    {
        parent::setUp();

        $this->vendor = Vendor::factory()->create();
        $this->otherVendor = Vendor::factory()->create();
        $this->internal = User::factory()->create();
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function dashboard(array $query = [], ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->internal)
            ->getJson('/api/dashboard'.($query === [] ? '' : '?'.http_build_query($query)));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function pmTask(PmStatus $status, array $attributes = []): PmTask
    {
        return PmTask::factory()->create([
            'status' => $status,
            'site_id' => 'S-1',
            'equipment_category_id' => 'CAT-1',
            'vendor_id' => $this->vendor->id,
            'work_done_date' => $status === PmStatus::Completed ? '2026-02-01' : null,
            ...$attributes,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function eCall(ECallStatus $status, array $attributes = []): ECall
    {
        return ECall::factory()->create([
            'status' => $status,
            'site_id' => 'S-1',
            'equipment_id' => 'EQ-1',
            'equipment_category_id' => 'CAT-1',
            'vendor_id' => $this->vendor->id,
            ...$attributes,
        ]);
    }

    /**
     * A Work Order with its own approved E-Call; the E-Call attributes decide site, category and vendor.
     *
     * @param  array<string, mixed>  $eCallAttributes
     */
    private function workOrder(WorkOrderStatus $status, array $eCallAttributes = []): WorkOrder
    {
        $eCall = $this->eCall(ECallStatus::Approved, $eCallAttributes);
        $proposal = $eCall->proposals()->create([
            'revision' => 1,
            'assessment_notes' => 'Replace part.',
            'fsr_disk' => 'local',
            'fsr_path' => 'fsr.pdf',
            'fsr_original_name' => 'fsr.pdf',
            'fsr_mime_type' => 'application/pdf',
            'fsr_size_bytes' => 100,
            'total_millicentavos' => 1_000_000,
            'total_centavos' => 1_000,
            'submitted_by_user_id' => User::factory()->vendorStaff($eCall->vendor)->create()->id,
            'submitted_at' => now(),
        ]);

        return WorkOrder::create([
            'e_call_id' => $eCall->id,
            'e_call_proposal_id' => $proposal->id,
            'status' => $status,
            'created_by_user_id' => $this->internal->id,
            'estimated_amount_millicentavos' => 1_000_000,
            'estimated_amount_centavos' => 1_000,
        ]);
    }

    /**
     * One record of each kind: an In Progress PM Task, a For EIC Review E-Call and a Closed Work Order (whose E-Call
     * is Approved).
     *
     * @param  array<string, mixed>  $attributes
     */
    private function oneOfEach(array $attributes): void
    {
        $this->pmTask(PmStatus::InProgress, $attributes);
        $this->eCall(ECallStatus::ForEicReview, $attributes);
        $this->workOrder(WorkOrderStatus::Closed, $attributes);
    }

    private function assertOneOfEach(TestResponse $response, int $expected): void
    {
        $response->assertOk()
            ->assertJsonPath('data.pm.by_status.in_progress', $expected)
            ->assertJsonPath('data.e_calls.by_status.for_eic_review', $expected)
            ->assertJsonPath('data.e_calls.by_status.approved', $expected)
            ->assertJsonPath('data.e_calls.open', 2 * $expected)
            ->assertJsonPath('data.work_orders.by_status.closed', $expected)
            ->assertJsonPath('data.work_orders.groups.closed', $expected);
    }

    public function test_requires_sign_in(): void
    {
        $this->getJson('/api/dashboard')->assertUnauthorized();
    }

    public function test_counts_only_open_pm_tasks_by_pm_status(): void
    {
        $this->pmTask(PmStatus::Scheduled);
        $this->pmTask(PmStatus::Scheduled);
        $this->pmTask(PmStatus::InProgress);
        $this->pmTask(PmStatus::ForValidation);
        $this->pmTask(PmStatus::ForValidation);
        $this->pmTask(PmStatus::ForValidation);
        $this->pmTask(PmStatus::Completed);
        $this->pmTask(PmStatus::Cancelled);

        $this->dashboard()
            ->assertOk()
            ->assertJsonPath('data.pm.by_status', ['scheduled' => 2, 'in_progress' => 1, 'for_validation' => 3, 'returned' => 0])
            ->assertJsonPath('data.e_calls', [
                'by_status' => array_fill_keys(array_column(ECallStatus::cases(), 'value'), 0),
                'open' => 0,
            ])
            ->assertJsonPath('data.work_orders', [
                'by_status' => array_fill_keys(array_column(WorkOrderStatus::cases(), 'value'), 0),
                'groups' => [
                    'awaiting_endorsement' => 0,
                    'under_implementation' => 0,
                    'awaiting_eic_validation' => 0,
                    'awaiting_billing_endorsement' => 0,
                    'closed' => 0,
                ],
            ]);
    }

    public function test_counts_e_calls_by_every_status_and_the_open_total(): void
    {
        $this->eCall(ECallStatus::ForVendorAssessment);
        $this->eCall(ECallStatus::ForVendorAssessment);
        $this->eCall(ECallStatus::ForEicReview);
        $this->eCall(ECallStatus::Approved);
        $this->eCall(ECallStatus::Returned);
        $this->eCall(ECallStatus::Closed);
        $this->eCall(ECallStatus::Rejected);
        $this->eCall(ECallStatus::Rejected);
        $this->eCall(ECallStatus::Cancelled);

        $this->dashboard()
            ->assertOk()
            ->assertExactJsonStructure(['data' => ['pm' => ['by_status' => ['scheduled', 'in_progress', 'for_validation', 'returned'], 'compliance' => ['period_from', 'period_to', 'due', 'on_time', 'percent'], 'completed_in_range'], 'e_calls' => ['by_status' => array_column(ECallStatus::cases(), 'value'), 'open'], 'work_orders' => ['by_status' => array_column(WorkOrderStatus::cases(), 'value'), 'groups' => ['awaiting_endorsement', 'under_implementation', 'awaiting_eic_validation', 'awaiting_billing_endorsement', 'closed']], 'equipment' => ['operating' => ['operational', 'non_operational', 'unknown'], 'requiring_attention' => ['count', 'items']], 'financials' => ['estimated' => ['total', 'by_site', 'by_vendor'], 'approved' => ['total', 'by_site', 'by_vendor'], 'billing' => ['total', 'by_site', 'by_vendor']]]])
            ->assertJsonPath('data.e_calls.by_status', [
                'for_vendor_assessment' => 2,
                'for_eic_review' => 1,
                'approved' => 1,
                'closed' => 1,
                'returned' => 1,
                'rejected' => 2,
                'cancelled' => 1,
            ])
            // 2 For Vendor Assessment + 1 For EIC Review + 1 Approved + 1 Returned.
            ->assertJsonPath('data.e_calls.open', 5);
    }

    public function test_counts_work_orders_by_every_status_and_the_groups(): void
    {
        $counts = [
            'open' => 1,
            'for_lvl1_endorsement' => 2,
            'for_lvl2_endorsement' => 1,
            'for_implementation' => 3,
            'implemented' => 1,
            'for_billing_endorsement_lvl1' => 1,
            'for_billing_endorsement_lvl2' => 0,
            'for_billing_endorsement_lvl3' => 2,
            'for_billing_endorsement_lvl4' => 1,
            'closed' => 2,
            'rejected' => 1,
            'cancelled' => 0,
        ];
        foreach ($counts as $status => $count) {
            for ($i = 0; $i < $count; $i++) {
                $this->workOrder(WorkOrderStatus::from($status));
            }
        }

        $this->dashboard()
            ->assertOk()
            ->assertJsonPath('data.work_orders.by_status', $counts)
            ->assertJsonPath('data.work_orders.groups', [
                'awaiting_endorsement' => 3,
                'under_implementation' => 3,
                'awaiting_eic_validation' => 1,
                'awaiting_billing_endorsement' => 4,
                'closed' => 2,
            ]);
    }

    public function test_site_filter_narrows_every_count(): void
    {
        $this->oneOfEach(['site_id' => 'S-1']);
        $this->oneOfEach(['site_id' => 'S-2']);
        $this->oneOfEach(['site_id' => 'S-2']);

        $this->assertOneOfEach($this->dashboard(['site_id' => 'S-1']), 1);
        $this->assertOneOfEach($this->dashboard(['site_id' => 'S-2']), 2);
        $this->assertOneOfEach($this->dashboard(), 3);
    }

    public function test_equipment_category_filter_narrows_every_count(): void
    {
        $this->oneOfEach(['equipment_category_id' => 'CAT-1']);
        $this->oneOfEach(['equipment_category_id' => 'CAT-2']);
        $this->oneOfEach(['equipment_category_id' => 'CAT-2']);

        $this->assertOneOfEach($this->dashboard(['equipment_category_id' => 'CAT-1']), 1);
        $this->assertOneOfEach($this->dashboard(['equipment_category_id' => 'CAT-2']), 2);
    }

    public function test_an_e_call_without_equipment_is_excluded_by_the_equipment_category_filter(): void
    {
        $this->eCall(ECallStatus::ForEicReview, ['equipment_id' => null, 'equipment_category_id' => null]);
        $this->eCall(ECallStatus::ForEicReview);

        $this->dashboard(['equipment_category_id' => 'CAT-1'])
            ->assertOk()
            ->assertJsonPath('data.e_calls.by_status.for_eic_review', 1)
            ->assertJsonPath('data.e_calls.open', 1);
        $this->dashboard()->assertJsonPath('data.e_calls.open', 2);
    }

    public function test_vendor_filter_narrows_every_count(): void
    {
        $this->oneOfEach(['vendor_id' => $this->vendor->id]);
        $this->oneOfEach(['vendor_id' => $this->otherVendor->id]);
        $this->oneOfEach(['vendor_id' => $this->otherVendor->id]);

        $this->assertOneOfEach($this->dashboard(['vendor_id' => $this->vendor->id]), 1);
        $this->assertOneOfEach($this->dashboard(['vendor_id' => $this->otherVendor->id]), 2);
    }

    public function test_filters_combine_with_and(): void
    {
        $this->oneOfEach(['site_id' => 'S-1', 'equipment_category_id' => 'CAT-1', 'vendor_id' => $this->vendor->id]);
        $this->oneOfEach(['site_id' => 'S-1', 'equipment_category_id' => 'CAT-1', 'vendor_id' => $this->otherVendor->id]);
        $this->oneOfEach(['site_id' => 'S-1', 'equipment_category_id' => 'CAT-2', 'vendor_id' => $this->vendor->id]);
        $this->oneOfEach(['site_id' => 'S-2', 'equipment_category_id' => 'CAT-1', 'vendor_id' => $this->vendor->id]);

        $this->assertOneOfEach($this->dashboard([
            'site_id' => 'S-1',
            'equipment_category_id' => 'CAT-1',
            'vendor_id' => $this->vendor->id,
        ]), 1);
        $this->assertOneOfEach($this->dashboard(['site_id' => 'S-1', 'equipment_category_id' => 'CAT-1']), 2);
    }

    public function test_vendor_staff_only_get_their_own_vendors_counts(): void
    {
        $this->oneOfEach(['vendor_id' => $this->vendor->id]);
        $this->oneOfEach(['vendor_id' => $this->otherVendor->id]);
        $this->oneOfEach(['vendor_id' => $this->otherVendor->id]);
        $staff = User::factory()->vendorStaff($this->vendor)->create();

        $this->assertOneOfEach($this->dashboard([], $staff), 1);
        $this->assertOneOfEach($this->dashboard(['vendor_id' => $this->otherVendor->id], $staff), 1);
    }

    /**
     * @return array<string, array{array<string, string>}>
     */
    public static function unknownFilters(): array
    {
        return [
            'site' => [['site_id' => 'NO-SUCH-SITE']],
            'equipment category' => [['equipment_category_id' => 'NO-SUCH-CAT']],
            'vendor' => [['vendor_id' => '999999']],
        ];
    }

    /**
     * @param  array<string, string>  $filter
     */
    #[DataProvider('unknownFilters')]
    public function test_an_unknown_filter_value_returns_all_zero_counts(array $filter): void
    {
        $this->oneOfEach([]);

        $this->assertOneOfEach($this->dashboard($filter), 0);
    }

    public function test_a_non_integer_vendor_id_is_rejected(): void
    {
        $this->dashboard(['vendor_id' => 'abc'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('vendor_id');
    }

    public function test_counts_use_one_grouped_query_per_section(): void
    {
        $this->oneOfEach([]);
        $this->oneOfEach([]);

        DB::enableQueryLog();
        $this->dashboard()->assertOk();
        $countQueries = array_filter(
            DB::getQueryLog(),
            fn (array $query): bool => str_contains(strtolower($query['query']), 'count(*)'),
        );

        // Three status sections plus pm.completed_in_range (issue 42).
        $this->assertCount(4, $countQueries);
    }
}
