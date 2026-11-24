<?php

namespace Tests\Feature;

use App\Enums\ApprovalAction;
use App\Enums\ECallPriority;
use App\Enums\ECallStatus;
use App\Enums\PmStatus;
use App\Models\ECall;
use App\Models\ECallProposal;
use App\Models\PmTask;
use App\Models\SiteSetting;
use App\Models\User;
use App\Models\Vendor;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * "Waiting for me" E-Call items (issue 39).
 */
class WaitingListECallApiTest extends TestCase
{
    use RefreshDatabase;

    private Vendor $vendor;

    private User $requestor;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-22 10:00:00');
        $this->vendor = Vendor::factory()->create();
        $this->requestor = self::employeeUser('P-REQ');
        SiteSetting::factory()->create(['site_id' => 'S-1', 'vendor_id' => $this->vendor->id, 'eic_employee_id' => 'P-EIC']);
        SiteSetting::factory()->create(['site_id' => 'S-2', 'vendor_id' => $this->vendor->id, 'eic_employee_id' => 'P-OTHER']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function eCall(ECallStatus $status, array $attributes = []): ECall
    {
        return ECall::factory()->create([
            'site_id' => 'S-1',
            'site_name_snapshot' => 'North Plant',
            'nature_of_issue' => 'Leaking pipe',
            'priority' => ECallPriority::High,
            'status' => $status,
            'requestor_user_id' => $this->requestor->id,
            'vendor_id' => $this->vendor->id,
            'eic_employee_id' => 'P-EIC',
            ...$attributes,
        ]);
    }

    private function proposal(ECall $eCall, int $revision, string $submittedAt): ECallProposal
    {
        return $eCall->proposals()->create([
            'revision' => $revision,
            'assessment_notes' => "Assessment revision {$revision}.",
            'fsr_disk' => 'local',
            'fsr_path' => "e-calls/{$eCall->id}/proposals/fsr-{$revision}.pdf",
            'fsr_original_name' => "fsr-{$revision}.pdf",
            'fsr_mime_type' => 'application/pdf',
            'fsr_size_bytes' => 100,
            'total_millicentavos' => 1_000_000,
            'total_centavos' => 1_000,
            'submitted_by_user_id' => $this->vendorStaff()->id,
            'submitted_at' => $submittedAt,
        ]);
    }

    private function approvalRecord(ECall $eCall, ApprovalAction $action, ECallStatus $from, ECallStatus $to, string $at): void
    {
        Carbon::setTestNow($at);
        $eCall->approvalRecords()->create([
            'action' => $action,
            'actor_user_id' => $this->eic()->id,
            'actor_employee_id' => 'P-EIC',
            'remarks' => 'Remarks.',
            'previous_status' => $from->value,
            'resulting_status' => $to->value,
        ]);
        Carbon::setTestNow('2026-11-22 10:00:00');
    }

    private function vendorStaff(?Vendor $vendor = null): User
    {
        return User::factory()->vendorStaff($vendor ?? $this->vendor)->create();
    }

    private function eic(string $employeeId = 'P-EIC'): User
    {
        return self::employeeUser($employeeId);
    }

    /**
     * @return list<int>
     */
    private function eCallIdsFor(User $user): array
    {
        $items = $this->actingAs($user)->getJson('/api/me/waiting')->assertOk()->json('data');

        return array_column(array_filter($items, fn (array $item): bool => $item['type'] === 'e_call'), 'id');
    }

    /**
     * Everyone who might plausibly see an E-Call: the Site Vendor's staff, another Vendor's staff, the current EIC,
     * a non-EIC employee, vendor staff with the EIC's employee ID, and the Requestor.
     *
     * @return array<string, User>
     */
    private function everyone(): array
    {
        return [
            'vendor staff' => $this->vendorStaff(),
            'other vendor staff' => $this->vendorStaff(Vendor::factory()->create()),
            'eic' => $this->eic(),
            'non-eic employee' => $this->eic('P-NOBODY'),
            'vendor staff with eic employee id' => self::vendorStaffWithEmployeeId('P-EIC', $this->vendor),
            'requestor' => $this->requestor,
        ];
    }

    /**
     * @param  list<string>  $expectedViewers
     */
    private function assertVisibleOnlyTo(ECall $eCall, array $expectedViewers): void
    {
        foreach ($this->everyone() as $who => $user) {
            $this->assertSame(
                in_array($who, $expectedViewers, true) ? [$eCall->id] : [],
                $this->eCallIdsFor($user),
                "Unexpected list for {$who}.",
            );
        }
    }

    public function test_for_vendor_assessment_waits_for_the_e_calls_vendor_staff_only(): void
    {
        $this->assertVisibleOnlyTo($this->eCall(ECallStatus::ForVendorAssessment), ['vendor staff', 'vendor staff with eic employee id']);
    }

    public function test_returned_waits_for_the_e_calls_vendor_staff_only(): void
    {
        $this->assertVisibleOnlyTo($this->eCall(ECallStatus::Returned), ['vendor staff', 'vendor staff with eic employee id']);
    }

    public function test_for_eic_review_waits_for_the_sites_current_eic_only(): void
    {
        $this->assertVisibleOnlyTo($this->eCall(ECallStatus::ForEicReview), ['eic']);
    }

    public function test_approved_waits_for_the_requestor_only(): void
    {
        $this->assertVisibleOnlyTo($this->eCall(ECallStatus::Approved), ['requestor']);
    }

    public function test_the_vendor_on_the_e_call_decides_not_the_sites_current_vendor(): void
    {
        $newVendor = Vendor::factory()->create();
        SiteSetting::query()->where('site_id', 'S-1')->update(['vendor_id' => $newVendor->id]);
        $eCall = $this->eCall(ECallStatus::ForVendorAssessment);

        $this->assertSame([$eCall->id], $this->eCallIdsFor($this->vendorStaff()));
        $this->assertSame([], $this->eCallIdsFor($this->vendorStaff($newVendor)));
    }

    public function test_closed_rejected_and_cancelled_e_calls_wait_for_nobody(): void
    {
        foreach ([ECallStatus::Closed, ECallStatus::Rejected, ECallStatus::Cancelled] as $status) {
            $this->eCall($status);
        }

        foreach ($this->everyone() as $who => $user) {
            $this->assertSame([], $this->eCallIdsFor($user), "Unexpected list for {$who}.");
        }
    }

    public function test_the_eic_on_the_e_call_record_is_history_only(): void
    {
        $this->eCall(ECallStatus::ForEicReview, ['site_id' => 'S-2', 'eic_employee_id' => 'P-EIC']);

        $this->assertSame([], $this->eCallIdsFor($this->eic()));
        $this->assertCount(1, $this->eCallIdsFor($this->eic('P-OTHER')));
    }

    public function test_a_replaced_eics_e_call_moves_to_the_new_eics_list(): void
    {
        $eCall = $this->eCall(ECallStatus::ForEicReview);
        $oldEic = $this->eic();
        $newEic = $this->eic('P-NEW');

        SiteSetting::query()->where('site_id', 'S-1')->update(['eic_employee_id' => 'P-NEW']);

        $this->assertSame([], $this->eCallIdsFor($oldEic));
        $this->assertSame([$eCall->id], $this->eCallIdsFor($newEic));
    }

    public function test_item_shape(): void
    {
        Carbon::setTestNow('2026-11-20 08:00:00');
        $eCall = $this->eCall(ECallStatus::ForEicReview, ['nature_of_issue' => 'Chiller tripping', 'priority' => ECallPriority::Emergency]);
        Carbon::setTestNow('2026-11-22 10:00:00');
        $this->proposal($eCall, 1, '2026-11-21 09:00:00');

        $this->actingAs($this->eic())
            ->getJson('/api/me/waiting')
            ->assertOk()
            ->assertExactJson(['data' => [[
                'type' => 'e_call',
                'id' => $eCall->id,
                'title' => 'Chiller tripping',
                'site' => ['id' => 'S-1', 'name' => 'North Plant'],
                'status' => 'for_eic_review',
                'priority' => 'emergency',
                'timeliness' => null,
                'waiting_since' => CarbonImmutable::parse('2026-11-21 09:00:00')->toJSON(),
            ]]]);
    }

    public function test_the_site_comes_from_the_snapshot(): void
    {
        $this->eCall(ECallStatus::ForVendorAssessment, ['site_name_snapshot' => 'Old Name']);

        $this->actingAs($this->vendorStaff())->getJson('/api/me/waiting')
            ->assertJsonPath('data.0.site', ['id' => 'S-1', 'name' => 'Old Name']);
    }

    public function test_a_new_e_call_waits_since_it_was_filed(): void
    {
        Carbon::setTestNow('2026-11-15 08:00:00');
        $this->eCall(ECallStatus::ForVendorAssessment);
        Carbon::setTestNow('2026-11-22 10:00:00');

        $this->actingAs($this->vendorStaff())->getJson('/api/me/waiting')
            ->assertJsonPath('data.0.waiting_since', CarbonImmutable::parse('2026-11-15 08:00:00')->toJSON());
    }

    public function test_for_eic_review_waits_since_the_latest_proposal_revision(): void
    {
        Carbon::setTestNow('2026-11-10 08:00:00');
        $eCall = $this->eCall(ECallStatus::ForEicReview);
        Carbon::setTestNow('2026-11-22 10:00:00');
        $this->proposal($eCall, 1, '2026-11-11 09:00:00');
        $this->approvalRecord($eCall, ApprovalAction::Return, ECallStatus::ForEicReview, ECallStatus::Returned, '2026-11-12 09:00:00');
        $this->proposal($eCall, 2, '2026-11-13 09:00:00');

        $this->actingAs($this->eic())->getJson('/api/me/waiting')
            ->assertJsonPath('data.0.waiting_since', CarbonImmutable::parse('2026-11-13 09:00:00')->toJSON());
    }

    public function test_returned_and_approved_wait_since_the_latest_approval_record(): void
    {
        Carbon::setTestNow('2026-11-10 08:00:00');
        $returned = $this->eCall(ECallStatus::Returned);
        $approved = $this->eCall(ECallStatus::Approved);
        Carbon::setTestNow('2026-11-22 10:00:00');
        $this->proposal($returned, 1, '2026-11-11 09:00:00');
        $this->approvalRecord($returned, ApprovalAction::Return, ECallStatus::ForEicReview, ECallStatus::Returned, '2026-11-14 09:00:00');
        $this->proposal($approved, 1, '2026-11-11 09:00:00');
        $this->approvalRecord($approved, ApprovalAction::Approve, ECallStatus::ForEicReview, ECallStatus::Approved, '2026-11-16 09:00:00');

        $this->actingAs($this->vendorStaff())->getJson('/api/me/waiting')
            ->assertJsonPath('data.0.waiting_since', CarbonImmutable::parse('2026-11-14 09:00:00')->toJSON());
        $this->actingAs($this->requestor)->getJson('/api/me/waiting')
            ->assertJsonPath('data.0.waiting_since', CarbonImmutable::parse('2026-11-16 09:00:00')->toJSON());
    }

    public function test_e_calls_sort_together_with_pm_tasks_oldest_first(): void
    {
        Carbon::setTestNow('2026-11-10 08:00:00');
        $oldECall = $this->eCall(ECallStatus::ForVendorAssessment);
        Carbon::setTestNow('2026-11-12 08:00:00');
        $task = PmTask::factory()->create([
            'site_id' => 'S-1',
            'site_name_snapshot' => 'North Plant',
            'vendor_id' => $this->vendor->id,
            'eic_employee_id' => 'P-EIC',
            'status' => PmStatus::Scheduled,
            'due_date' => '2026-11-25',
            'due_from_date' => '2026-11-01',
        ]);
        Carbon::setTestNow('2026-11-14 08:00:00');
        $newECall = $this->eCall(ECallStatus::ForVendorAssessment);
        Carbon::setTestNow('2026-11-22 10:00:00');

        $response = $this->actingAs($this->vendorStaff())->getJson('/api/me/waiting')->assertOk();

        $this->assertSame(
            [['e_call', $oldECall->id], ['pm_task', $task->id], ['e_call', $newECall->id]],
            array_map(fn (array $item): array => [$item['type'], $item['id']], $response->json('data')),
        );
        $response->assertJsonPath('data.1.priority', null);
    }

    public function test_the_query_count_does_not_grow_with_the_number_of_e_calls(): void
    {
        $users = [$this->vendorStaff(), $this->eic(), $this->requestor];
        $seed = function (): void {
            $this->eCall(ECallStatus::ForVendorAssessment);
            $returned = $this->eCall(ECallStatus::Returned);
            $this->proposal($returned, 1, '2026-11-20 09:00:00');
            $this->approvalRecord($returned, ApprovalAction::Return, ECallStatus::ForEicReview, ECallStatus::Returned, '2026-11-21 09:00:00');
            $review = $this->eCall(ECallStatus::ForEicReview);
            $this->proposal($review, 1, '2026-11-20 09:00:00');
            $this->eCall(ECallStatus::Approved);
        };

        $countQueries = function (User $user): int {
            $this->actingAs($user);
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson('/api/me/waiting')->assertOk();
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };

        $seed();
        $before = array_map($countQueries, $users);

        foreach (range(1, 4) as $i) {
            $seed();
        }

        $this->assertSame($before, array_map($countQueries, $users));
    }
}
