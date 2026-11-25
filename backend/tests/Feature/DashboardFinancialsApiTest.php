<?php

namespace Tests\Feature;

use App\Enums\ApprovalAction;
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
 * Dashboard date range and financial figures (issue 42): `from`/`to`, pm.completed_in_range and financials
 * (estimated, approved, billing) on /api/dashboard. The clock is frozen at 2026-11-25 10:00 in Manila.
 */
class DashboardFinancialsApiTest extends TestCase
{
    use RefreshDatabase;

    private Vendor $acme;

    private Vendor $zenith;

    private User $internal;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(now('Asia/Manila')->setDate(2026, 11, 25)->setTime(10, 0));

        $this->acme = Vendor::factory()->create(['name' => 'Acme Services']);
        $this->zenith = Vendor::factory()->create(['name' => 'Zenith Works']);
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
     * A Work Order with its own E-Call. Site S-1 is "Alpha Site" and S-2 is "Bravo Site".
     *
     * @param  array{site?: string, vendor?: Vendor, category?: string, created?: string, estimated?: int, billing?: int|null}  $options
     */
    private function workOrder(WorkOrderStatus $status, array $options = []): WorkOrder
    {
        $site = $options['site'] ?? 'S-1';
        $vendor = $options['vendor'] ?? $this->acme;
        $eCall = ECall::factory()->create([
            'status' => ECallStatus::Approved,
            'site_id' => $site,
            'site_name_snapshot' => $site === 'S-1' ? 'Alpha Site' : 'Bravo Site',
            'equipment_id' => 'EQ-1',
            'equipment_category_id' => $options['category'] ?? 'CAT-1',
            'vendor_id' => $vendor->id,
        ]);
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
            'submitted_by_user_id' => User::factory()->vendorStaff($vendor)->create()->id,
            'submitted_at' => now(),
        ]);

        $estimated = $options['estimated'] ?? 1_000;
        $workOrder = WorkOrder::create([
            'e_call_id' => $eCall->id,
            'e_call_proposal_id' => $proposal->id,
            'status' => $status,
            'created_by_user_id' => $this->internal->id,
            'estimated_amount_millicentavos' => $estimated * 1_000,
            'estimated_amount_centavos' => $estimated,
        ]);

        DB::table('work_orders')->where('id', $workOrder->id)->update([
            'created_at' => $options['created'] ?? '2026-11-10 09:00:00',
            'billing_amount_centavos' => $options['billing'] ?? null,
            'billing_amount_millicentavos' => isset($options['billing']) ? $options['billing'] * 1_000 : null,
        ]);

        return $workOrder;
    }

    private function record(WorkOrder $workOrder, ApprovalAction $action, WorkOrderStatus $from, WorkOrderStatus $to, string $at): void
    {
        DB::table('approval_records')->insert([
            'subject_type' => 'work_order',
            'subject_id' => $workOrder->id,
            'action' => $action->value,
            'actor_user_id' => $this->internal->id,
            'previous_status' => $from->value,
            'resulting_status' => $to->value,
            'created_at' => $at,
        ]);
    }

    private function lvl2Approved(WorkOrder $workOrder, string $at): void
    {
        $this->record($workOrder, ApprovalAction::Approve, WorkOrderStatus::ForLvl2Endorsement, WorkOrderStatus::ForImplementation, $at);
    }

    private function closed(WorkOrder $workOrder, string $at): void
    {
        $this->record($workOrder, ApprovalAction::Approve, WorkOrderStatus::ForBillingEndorsementLvl1, WorkOrderStatus::Closed, $at);
    }

    /**
     * The hand-calculated fixture for the range 2026-11-01 .. 2026-11-15 (amounts in centavos).
     */
    private function seedFixture(): void
    {
        // In estimated (created on the first boundary) and approved (LVL2 on the last second of the range).
        $a = $this->workOrder(WorkOrderStatus::ForImplementation, ['created' => '2026-11-01 00:00:00', 'estimated' => 10_000]);
        $this->lvl2Approved($a, '2026-11-15 23:59:59');

        // In estimated (last boundary) and billing; LVL2 a day before the range, so out of approved.
        $b = $this->workOrder(WorkOrderStatus::Closed, ['site' => 'S-2', 'vendor' => $this->zenith, 'created' => '2026-11-15 23:59:59', 'estimated' => 25_050, 'billing' => 24_999]);
        $this->lvl2Approved($b, '2026-10-31 23:59:59');
        $this->closed($b, '2026-11-10 14:00:00');

        // Created a day after the range: out of everything.
        $this->workOrder(WorkOrderStatus::ForLvl1Endorsement, ['site' => 'S-2', 'vendor' => $this->zenith, 'created' => '2026-11-16 00:00:00', 'estimated' => 70_000]);

        // Cancelled: out of estimated.
        $this->workOrder(WorkOrderStatus::Cancelled, ['created' => '2026-11-05 09:00:00', 'estimated' => 50_000]);

        // Rejected after LVL2 approval: out of estimated and approved.
        $e = $this->workOrder(WorkOrderStatus::Rejected, ['site' => 'S-2', 'created' => '2026-11-03 09:00:00', 'estimated' => 30_000]);
        $this->lvl2Approved($e, '2026-11-04 09:00:00');

        // Still in billing endorsement: in estimated and approved, out of billing.
        $f = $this->workOrder(WorkOrderStatus::ForBillingEndorsementLvl2, ['created' => '2026-11-02 09:00:00', 'estimated' => 40_001, 'billing' => 39_999]);
        $this->lvl2Approved($f, '2026-11-02 15:00:00');

        // Created before the range; approved twice, the latest inside it; closed a day after the range.
        $g = $this->workOrder(WorkOrderStatus::Closed, ['created' => '2026-10-01 09:00:00', 'estimated' => 5_000, 'billing' => 6_000]);
        $this->lvl2Approved($g, '2026-10-15 09:00:00');
        $this->lvl2Approved($g, '2026-11-01 00:00:00');
        $this->closed($g, '2026-11-16 00:00:00');

        // Approved twice, the latest after the range: out of approved.
        $j = $this->workOrder(WorkOrderStatus::ForImplementation, ['site' => 'S-2', 'vendor' => $this->zenith, 'created' => '2026-09-01 09:00:00', 'estimated' => 8_000]);
        $this->lvl2Approved($j, '2026-11-03 09:00:00');
        $this->lvl2Approved($j, '2026-11-20 09:00:00');
    }

    public function test_financials_match_the_hand_calculated_sums_in_total_by_site_and_by_vendor(): void
    {
        $this->seedFixture();

        $this->dashboard(['from' => '2026-11-01', 'to' => '2026-11-15'])
            ->assertOk()
            ->assertJsonPath('data.financials', [
                'estimated' => [
                    // a 10000 + b 25050 + f 40001
                    'total' => '750.51',
                    'by_site' => [
                        ['site_id' => 'S-1', 'site_name' => 'Alpha Site', 'amount' => '500.01'],
                        ['site_id' => 'S-2', 'site_name' => 'Bravo Site', 'amount' => '250.50'],
                    ],
                    'by_vendor' => [
                        ['vendor_id' => $this->acme->id, 'vendor_name' => 'Acme Services', 'amount' => '500.01'],
                        ['vendor_id' => $this->zenith->id, 'vendor_name' => 'Zenith Works', 'amount' => '250.50'],
                    ],
                ],
                'approved' => [
                    // a 10000 + f 40001 + g 5000
                    'total' => '550.01',
                    'by_site' => [
                        ['site_id' => 'S-1', 'site_name' => 'Alpha Site', 'amount' => '550.01'],
                    ],
                    'by_vendor' => [
                        ['vendor_id' => $this->acme->id, 'vendor_name' => 'Acme Services', 'amount' => '550.01'],
                    ],
                ],
                'billing' => [
                    // b 24999
                    'total' => '249.99',
                    'by_site' => [
                        ['site_id' => 'S-2', 'site_name' => 'Bravo Site', 'amount' => '249.99'],
                    ],
                    'by_vendor' => [
                        ['vendor_id' => $this->zenith->id, 'vendor_name' => 'Zenith Works', 'amount' => '249.99'],
                    ],
                ],
            ]);
    }

    public function test_nothing_in_range_gives_zero_totals_and_no_rows(): void
    {
        $this->seedFixture();

        $empty = ['total' => '0.00', 'by_site' => [], 'by_vendor' => []];
        $this->dashboard(['from' => '2025-01-01', 'to' => '2025-01-31'])
            ->assertOk()
            ->assertJsonPath('data.financials', ['estimated' => $empty, 'approved' => $empty, 'billing' => $empty])
            ->assertJsonPath('data.pm.completed_in_range', 0);
    }

    public function test_without_from_and_to_the_period_is_the_current_manila_month(): void
    {
        $this->workOrder(WorkOrderStatus::Open, ['created' => '2026-11-01 00:00:00', 'estimated' => 1_234]);
        $this->workOrder(WorkOrderStatus::Open, ['created' => '2026-10-31 23:59:59', 'estimated' => 99_999]);
        $this->workOrder(WorkOrderStatus::Open, ['created' => '2026-11-30 23:59:59', 'estimated' => 1]);
        $this->workOrder(WorkOrderStatus::Open, ['created' => '2026-12-01 00:00:00', 'estimated' => 88_888]);

        $this->dashboard()
            ->assertOk()
            ->assertJsonPath('data.financials.estimated.total', '12.35')
            ->assertJsonPath('data.pm.compliance.period_from', '2026-11-01')
            ->assertJsonPath('data.pm.compliance.period_to', '2026-11-30');
    }

    public function test_the_default_month_follows_the_manila_date_not_utc(): void
    {
        // 2026-11-30 17:00 UTC is 2026-12-01 01:00 in Manila.
        $this->travelTo(now('UTC')->setDate(2026, 11, 30)->setTime(17, 0));
        $this->workOrder(WorkOrderStatus::Open, ['created' => '2026-11-30 09:00:00', 'estimated' => 500]);
        $this->workOrder(WorkOrderStatus::Open, ['created' => '2026-12-01 00:30:00', 'estimated' => 700]);

        $this->dashboard()
            ->assertOk()
            ->assertJsonPath('data.pm.compliance.period_from', '2026-12-01')
            ->assertJsonPath('data.financials.estimated.total', '7.00');
    }

    public function test_from_and_to_also_drive_pm_compliance(): void
    {
        $this->dashboard(['from' => '2026-10-05', 'to' => '2026-10-20'])
            ->assertOk()
            ->assertJsonPath('data.pm.compliance.period_from', '2026-10-05')
            ->assertJsonPath('data.pm.compliance.period_to', '2026-10-20');
    }

    /**
     * @return array<string, array{array<string, string>, string}>
     */
    public static function invalidRanges(): array
    {
        return [
            'from after to' => [['from' => '2026-11-16', 'to' => '2026-11-15'], 'to'],
            'bad from' => [['from' => '2026-13-01', 'to' => '2026-11-15'], 'from'],
            'bad to format' => [['from' => '2026-11-01', 'to' => '15/11/2026'], 'to'],
            'not a date' => [['from' => 'yesterday', 'to' => '2026-11-15'], 'from'],
            'from without to' => [['from' => '2026-11-01'], 'to'],
            'to without from' => [['to' => '2026-11-15'], 'from'],
        ];
    }

    /**
     * @param  array<string, string>  $query
     */
    #[DataProvider('invalidRanges')]
    public function test_an_invalid_range_is_rejected(array $query, string $field): void
    {
        $this->dashboard($query)
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);
    }

    public function test_a_one_day_range_is_allowed(): void
    {
        $this->workOrder(WorkOrderStatus::Open, ['created' => '2026-11-10 12:00:00', 'estimated' => 300]);

        $this->dashboard(['from' => '2026-11-10', 'to' => '2026-11-10'])
            ->assertOk()
            ->assertJsonPath('data.financials.estimated.total', '3.00');
    }

    public function test_pm_completed_in_range_counts_completed_tasks_by_work_done_date(): void
    {
        $task = fn (PmStatus $status, ?string $workDone, array $attributes = []) => PmTask::factory()->create([
            'status' => $status,
            'site_id' => 'S-1',
            'equipment_category_id' => 'CAT-1',
            'vendor_id' => $this->acme->id,
            'work_done_date' => $workDone,
            ...$attributes,
        ]);
        $task(PmStatus::Completed, '2026-11-01');
        $task(PmStatus::Completed, '2026-11-15');
        $task(PmStatus::Completed, '2026-11-16');
        $task(PmStatus::Completed, '2026-10-31');
        $task(PmStatus::ForValidation, '2026-11-05');
        $task(PmStatus::Completed, '2026-11-05', ['site_id' => 'S-2', 'vendor_id' => $this->zenith->id]);
        $task(PmStatus::Completed, '2026-11-06', ['equipment_category_id' => 'CAT-2']);

        $range = ['from' => '2026-11-01', 'to' => '2026-11-15'];
        $this->dashboard($range)->assertOk()->assertJsonPath('data.pm.completed_in_range', 4);
        $this->dashboard([...$range, 'site_id' => 'S-1'])->assertJsonPath('data.pm.completed_in_range', 3);
        $this->dashboard([...$range, 'equipment_category_id' => 'CAT-2'])->assertJsonPath('data.pm.completed_in_range', 1);
        $this->dashboard([...$range, 'vendor_id' => $this->zenith->id])->assertJsonPath('data.pm.completed_in_range', 1);

        $staff = User::factory()->vendorStaff($this->zenith)->create();
        $this->dashboard([...$range, 'vendor_id' => $this->acme->id], $staff)->assertJsonPath('data.pm.completed_in_range', 1);
    }

    public function test_site_category_and_vendor_filters_narrow_the_financials(): void
    {
        $this->workOrder(WorkOrderStatus::Open, ['site' => 'S-1', 'vendor' => $this->acme, 'category' => 'CAT-1', 'estimated' => 100]);
        $this->workOrder(WorkOrderStatus::Open, ['site' => 'S-2', 'vendor' => $this->acme, 'category' => 'CAT-1', 'estimated' => 200]);
        $this->workOrder(WorkOrderStatus::Open, ['site' => 'S-1', 'vendor' => $this->zenith, 'category' => 'CAT-2', 'estimated' => 400]);

        $this->dashboard(['site_id' => 'S-1'])->assertJsonPath('data.financials.estimated.total', '5.00');
        $this->dashboard(['equipment_category_id' => 'CAT-1'])->assertJsonPath('data.financials.estimated.total', '3.00');
        $this->dashboard(['vendor_id' => $this->zenith->id])
            ->assertJsonPath('data.financials.estimated.total', '4.00')
            ->assertJsonPath('data.financials.estimated.by_vendor', [
                ['vendor_id' => $this->zenith->id, 'vendor_name' => 'Zenith Works', 'amount' => '4.00'],
            ]);
        $this->dashboard(['site_id' => 'S-1', 'vendor_id' => $this->acme->id])->assertJsonPath('data.financials.estimated.total', '1.00');
        $this->dashboard(['site_id' => 'NO-SUCH-SITE'])->assertJsonPath('data.financials.estimated.total', '0.00');
    }

    public function test_vendor_staff_only_see_their_own_vendors_financials(): void
    {
        $this->seedFixture();
        $staff = User::factory()->vendorStaff($this->zenith)->create();

        $this->dashboard(['from' => '2026-11-01', 'to' => '2026-11-15', 'vendor_id' => $this->acme->id], $staff)
            ->assertOk()
            ->assertJsonPath('data.financials.estimated.total', '250.50')
            ->assertJsonPath('data.financials.estimated.by_vendor', [
                ['vendor_id' => $this->zenith->id, 'vendor_name' => 'Zenith Works', 'amount' => '250.50'],
            ])
            ->assertJsonPath('data.financials.approved.total', '0.00')
            ->assertJsonPath('data.financials.billing.total', '249.99');
    }

    public function test_the_range_does_not_change_the_current_status_counts(): void
    {
        $this->seedFixture();

        $all = $this->dashboard()->assertOk()->json('data');
        $ranged = $this->dashboard(['from' => '2025-01-01', 'to' => '2025-01-31'])->assertOk()->json('data');

        $this->assertSame(1, $all['work_orders']['by_status']['cancelled']);
        $this->assertSame($all['work_orders'], $ranged['work_orders']);
        $this->assertSame($all['e_calls'], $ranged['e_calls']);
        $this->assertSame($all['pm']['by_status'], $ranged['pm']['by_status']);
    }

    public function test_the_number_of_queries_does_not_grow_with_the_work_orders(): void
    {
        $this->seedFixture();
        $range = ['from' => '2026-11-01', 'to' => '2026-11-15'];
        $this->dashboard($range)->assertOk();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->dashboard($range)->assertOk();
        $before = count(DB::getQueryLog());

        $this->seedFixture();
        DB::flushQueryLog();
        $this->dashboard($range)->assertOk()->assertJsonPath('data.financials.estimated.total', '1501.02');
        $this->assertCount($before, DB::getQueryLog());
    }
}
