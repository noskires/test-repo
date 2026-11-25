<?php

namespace Tests\Feature;

use App\Enums\PmStatus;
use App\Enums\PssRole;
use App\Models\PmTask;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * Check-up compliance report (issue 29): /api/reports/pm-compliance and its .xlsx export, the view-reports gate and
 * Vendor scoping. The clock is frozen at 2026-11-20 10:00 in Manila, so the default period is November 2026 and
 * tasks due after the 20th are not yet counted.
 */
class PmComplianceReportApiTest extends TestCase
{
    use RefreshDatabase;

    private Vendor $vendor;

    private Vendor $otherVendor;

    private User $viewer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(now('Asia/Manila')->setDate(2026, 11, 20)->setTime(10, 0));

        $this->vendor = Vendor::factory()->create(['name' => 'Alpha Services']);
        $this->otherVendor = Vendor::factory()->create(['name' => 'Beta Works']);
        $this->viewer = User::factory()->create();
        $this->viewer->grantRole(PssRole::ManagementViewer);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function pmTask(string $site, string $equipment, PmStatus $status, string $dueDate, ?string $workDoneDate = null, array $attributes = []): PmTask
    {
        return PmTask::factory()->create([
            'site_id' => 'S-'.$site,
            'site_name_snapshot' => "Site {$site}",
            'equipment_id' => 'EQ-'.$site.'-'.$equipment,
            'equipment_name_snapshot' => "Equipment {$equipment}",
            'equipment_category_id' => 'CAT-1',
            'equipment_category_snapshot' => 'Genset',
            'vendor_id' => $this->vendor->id,
            'status' => $status,
            'due_from_date' => $dueDate,
            'due_date' => $dueDate,
            'work_done_date' => $workDoneDate,
            ...$attributes,
        ]);
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function report(array $query = [], ?User $as = null, string $suffix = ''): TestResponse
    {
        return $this->actingAs($as ?? $this->viewer)
            ->getJson('/api/reports/pm-compliance'.$suffix.($query === [] ? '' : '?'.http_build_query($query)));
    }

    /**
     * @param  array<string, mixed>  $query
     * @return list<list<string|null>>
     */
    private function exportedRows(array $query = [], ?User $as = null): array
    {
        $response = $this->report($query, $as, '/export')->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'pss-xlsx');
        file_put_contents($path, $response->streamedContent());
        try {
            $sheets = IOFactory::createReader('Xlsx')->load($path);
            $this->assertSame(1, $sheets->getSheetCount());

            return $sheets->getActiveSheet()->toArray(null, false, false, false);
        } finally {
            unlink($path);
        }
    }

    public function test_returns_the_period_summary_and_one_row_per_due_task_sorted_by_due_date_site_and_equipment(): void
    {
        $this->pmTask('B', 'Pump', PmStatus::Completed, '2026-11-05', '2026-11-04');
        $this->pmTask('A', 'Zeta', PmStatus::Completed, '2026-11-05', '2026-11-07');
        $this->pmTask('A', 'Alpha', PmStatus::Scheduled, '2026-11-05');
        $this->pmTask('A', 'First', PmStatus::ForValidation, '2026-11-02', '2026-11-01', ['vendor_id' => $this->otherVendor->id]);
        $this->pmTask('A', 'Cancelled', PmStatus::Cancelled, '2026-11-03');
        $this->pmTask('A', 'Future', PmStatus::Scheduled, '2026-11-21');
        $this->pmTask('A', 'October', PmStatus::Scheduled, '2026-10-31');

        $this->report()->assertOk()->assertExactJson([
            'period' => ['from' => '2026-11-01', 'to' => '2026-11-30'],
            'summary' => ['due' => 4, 'on_time' => 1, 'compliance' => 25],
            'rows' => [
                $this->row('Site A', 'Equipment First', 'Beta Works', '2026-11-02', '2026-11-01', 'for_validation', false),
                $this->row('Site A', 'Equipment Alpha', 'Alpha Services', '2026-11-05', null, 'scheduled', false),
                $this->row('Site A', 'Equipment Zeta', 'Alpha Services', '2026-11-05', '2026-11-07', 'completed', false),
                $this->row('Site B', 'Equipment Pump', 'Alpha Services', '2026-11-05', '2026-11-04', 'completed', true),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(string $site, string $equipment, string $vendor, string $due, ?string $workDone, string $status, bool $onTime): array
    {
        return [
            'site_name' => $site,
            'equipment_name' => $equipment,
            'equipment_category' => 'Genset',
            'vendor_name' => $vendor,
            'due_date' => $due,
            'work_done_date' => $workDone,
            'status' => $status,
            'on_time' => $onTime,
        ];
    }

    public function test_the_summary_equals_the_dashboards_pm_compliance_and_the_row_count_on_the_first_and_last_day(): void
    {
        $this->pmTask('A', 'One', PmStatus::Completed, '2026-10-01', '2026-10-01');
        $this->pmTask('A', 'Two', PmStatus::Scheduled, '2026-10-31');
        $this->pmTask('A', 'Before', PmStatus::Scheduled, '2026-09-30');
        $this->pmTask('A', 'After', PmStatus::Scheduled, '2026-11-01');
        $period = ['from' => '2026-10-01', 'to' => '2026-10-31'];

        $report = $this->report($period)->assertOk()->json();
        $dashboard = $this->actingAs($this->viewer)->getJson('/api/dashboard?'.http_build_query($period))->assertOk()->json('data.pm.compliance');

        $this->assertSame(['due' => 2, 'on_time' => 1, 'compliance' => 50], $report['summary']);
        $this->assertSame(
            [$dashboard['due'], $dashboard['on_time'], $dashboard['percent']],
            [$report['summary']['due'], $report['summary']['on_time'], $report['summary']['compliance']],
        );
        $this->assertCount($report['summary']['due'], $report['rows']);
        $this->assertSame(['2026-10-01', '2026-10-31'], array_column($report['rows'], 'due_date'));
    }

    public function test_compliance_is_null_when_nothing_is_due(): void
    {
        $this->report()->assertOk()->assertJsonPath('summary', ['due' => 0, 'on_time' => 0, 'compliance' => null])
            ->assertJsonPath('rows', []);
    }

    public function test_filters_narrow_rows_by_site_category_and_vendor(): void
    {
        $this->pmTask('A', 'Keep', PmStatus::Scheduled, '2026-11-02');
        $this->pmTask('B', 'OtherSite', PmStatus::Scheduled, '2026-11-02');
        $this->pmTask('A', 'OtherCategory', PmStatus::Scheduled, '2026-11-02', null, ['equipment_category_id' => 'CAT-2']);
        $this->pmTask('A', 'OtherVendor', PmStatus::Scheduled, '2026-11-02', null, ['vendor_id' => $this->otherVendor->id]);

        $this->report(['site_id' => 'S-A', 'equipment_category_id' => 'CAT-1', 'vendor_id' => $this->vendor->id])
            ->assertOk()
            ->assertJsonPath('summary.due', 1)
            ->assertJsonPath('rows.0.equipment_name', 'Equipment Keep')
            ->assertJsonCount(1, 'rows');
    }

    public function test_from_and_to_are_validated_like_the_dashboard(): void
    {
        $this->report(['from' => '2026-11-01'])->assertUnprocessable()->assertJsonValidationErrors('to');
        $this->report(['from' => '2026-11-30', 'to' => '2026-11-01'])->assertUnprocessable()->assertJsonValidationErrors('to');
        $this->report(['from' => '01/11/2026', 'to' => '2026-11-30'])->assertUnprocessable()->assertJsonValidationErrors('from');
        $this->report(['vendor_id' => 'abc'])->assertUnprocessable()->assertJsonValidationErrors('vendor_id');
        $this->report(['from' => '2026-11-01'], null, '/export')->assertUnprocessable()->assertJsonValidationErrors('to');
    }

    public function test_the_default_period_is_the_current_manila_month(): void
    {
        $this->travelTo(now('Asia/Manila')->setDate(2026, 12, 1)->setTime(0, 30));

        $this->report()->assertOk()->assertJsonPath('period', ['from' => '2026-12-01', 'to' => '2026-12-31']);
    }

    public function test_a_system_administrator_may_view_reports(): void
    {
        $admin = User::factory()->create();
        $admin->grantRole(PssRole::SystemAdministrator);

        $this->report([], $admin)->assertOk();
        $this->report([], $admin, '/export')->assertOk();
    }

    public function test_a_management_viewer_may_view_reports(): void
    {
        $this->report()->assertOk();
        $this->report([], null, '/export')->assertOk();
    }

    public function test_an_internal_employee_without_a_report_role_gets_403(): void
    {
        $employee = User::factory()->create();

        $this->report([], $employee)->assertForbidden();
        $this->report([], $employee, '/export')->assertForbidden();
    }

    public function test_vendor_staff_without_management_viewer_get_403(): void
    {
        $staff = User::factory()->vendorStaff($this->vendor)->create();

        $this->report([], $staff)->assertForbidden();
        $this->report([], $staff, '/export')->assertForbidden();
    }

    public function test_a_pending_user_gets_403_from_the_pending_block(): void
    {
        $pending = User::factory()->pending()->create();
        $pending->grantRole(PssRole::ManagementViewer);

        $this->report([], $pending)->assertForbidden()->assertJsonPath('message', 'Your account is waiting for an administrator to set up your access.');
        $this->report([], $pending, '/export')->assertForbidden()->assertJsonPath('message', 'Your account is waiting for an administrator to set up your access.');
    }

    public function test_a_vendor_linked_viewer_sees_only_their_own_vendors_tasks_even_on_a_shared_site(): void
    {
        $vendorViewer = User::factory()->vendorStaff($this->vendor)->create();
        $vendorViewer->grantRole(PssRole::ManagementViewer);
        $this->pmTask('A', 'Mine', PmStatus::Completed, '2026-11-02', '2026-11-02');
        $this->pmTask('A', 'Theirs', PmStatus::Scheduled, '2026-11-02', null, ['vendor_id' => $this->otherVendor->id]);

        $expected = [
            'summary' => ['due' => 1, 'on_time' => 1, 'compliance' => 100],
            'names' => ['Equipment Mine'],
        ];
        foreach ([[], ['vendor_id' => $this->otherVendor->id]] as $query) {
            $json = $this->report($query, $vendorViewer)->assertOk()->json();
            $this->assertSame($expected, ['summary' => $json['summary'], 'names' => array_column($json['rows'], 'equipment_name')]);
        }

        $rows = $this->exportedRows(['vendor_id' => $this->otherVendor->id], $vendorViewer);
        $this->assertCount(2, $rows);
        $this->assertSame('Equipment Mine', $rows[1][1]);

        $this->report([], $this->viewer)->assertOk()->assertJsonCount(2, 'rows');
    }

    public function test_export_downloads_an_xlsx_with_the_header_and_data_rows(): void
    {
        $this->pmTask('A', 'Late', PmStatus::Completed, '2026-11-03', '2026-11-04');
        $this->pmTask('A', 'Open', PmStatus::Scheduled, '2026-11-03');
        $this->pmTask('B', 'Done', PmStatus::Completed, '2026-11-02', '2026-11-02');

        $response = $this->report(['from' => '2026-11-01', 'to' => '2026-11-30'], null, '/export')->assertOk();
        $response->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->assertDownload('pm-compliance-2026-11-01-to-2026-11-30.xlsx');

        $this->assertSame([
            ['Site', 'Equipment', 'Equipment category', 'Vendor', 'Due date', 'Work done date', 'Status', 'On time'],
            ['Site B', 'Equipment Done', 'Genset', 'Alpha Services', '2026-11-02', '2026-11-02', 'completed', 'Yes'],
            ['Site A', 'Equipment Late', 'Genset', 'Alpha Services', '2026-11-03', '2026-11-04', 'completed', 'No'],
            ['Site A', 'Equipment Open', 'Genset', 'Alpha Services', '2026-11-03', null, 'scheduled', 'No'],
        ], $this->exportedRows(['from' => '2026-11-01', 'to' => '2026-11-30']));
    }

    public function test_export_filename_uses_the_default_period(): void
    {
        $this->report([], null, '/export')->assertOk()->assertDownload('pm-compliance-2026-11-01-to-2026-11-30.xlsx');
    }

    public function test_dates_are_written_as_text_cells(): void
    {
        $this->pmTask('A', 'Done', PmStatus::Completed, '2026-11-02', '2026-11-02');

        $response = $this->report([], null, '/export')->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'pss-xlsx');
        file_put_contents($path, $response->streamedContent());
        try {
            $sheet = IOFactory::createReader('Xlsx')->load($path)->getActiveSheet();
            $this->assertSame('s', $sheet->getCell('E2')->getDataType());
            $this->assertSame('s', $sheet->getCell('F2')->getDataType());
        } finally {
            unlink($path);
        }
    }

    public function test_the_query_count_does_not_grow_with_the_number_of_rows(): void
    {
        $this->assertSame($this->queriesFor(1, ''), $this->queriesFor(4, ''));
        $this->assertSame($this->queriesFor(1, '/export'), $this->queriesFor(4, '/export'));
    }

    private function queriesFor(int $rows, string $suffix): int
    {
        static $next = 0;
        for ($i = 0; $i < $rows; $i++) {
            $vendor = Vendor::factory()->create();
            $this->pmTask('Q', 'Q'.(++$next), PmStatus::Completed, '2026-11-02', '2026-11-02', ['vendor_id' => $vendor->id]);
        }
        $this->viewer->unsetRelation('roles');

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->report([], null, $suffix)->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    }
}
