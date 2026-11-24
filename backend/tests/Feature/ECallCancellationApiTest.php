<?php

namespace Tests\Feature;

use App\Enums\ApprovalAction;
use App\Enums\ECallStatus;
use App\Models\ApprovalRecord;
use App\Models\ECall;
use App\Models\PmTask;
use App\Models\PriceListItem;
use App\Models\SiteSetting;
use App\Models\User;
use App\Models\Vendor;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Cancelling an E-Call (issue 25): its Requestor or the Site's CURRENT EIC, with remarks, until the EIC approves the
 * FSR and BOQ (For Vendor Assessment, For EIC Review or Returned).
 */
class ECallCancellationApiTest extends TestCase
{
    use RefreshDatabase;

    private Vendor $siteVendor;

    private User $requestor;

    private ECall $eCall;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-24 10:00:00');
        Storage::fake(config('pss.e_call_attachments.disk'));

        $this->siteVendor = Vendor::factory()->create();
        SiteSetting::factory()->create(['site_id' => 'S-1', 'vendor_id' => $this->siteVendor->id, 'eic_employee_id' => 'P-EIC']);
        $this->requestor = self::employeeUser('P-REQ');
        $this->eCall = ECall::factory()->create([
            'site_id' => 'S-1',
            'vendor_id' => $this->siteVendor->id,
            'eic_employee_id' => 'P-EIC',
            'requestor_user_id' => $this->requestor->id,
            'requestor_employee_id' => 'P-REQ',
            'status' => ECallStatus::ForVendorAssessment,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function cancel(array $payload = ['remarks' => 'Raised by mistake.']): TestResponse
    {
        return $this->postJson("/api/e-calls/{$this->eCall->id}/cancel", $payload);
    }

    private function setStatus(ECallStatus $status): void
    {
        DB::table('e_calls')->where('id', $this->eCall->id)->update(['status' => $status->value]);
    }

    private function eic(): User
    {
        return self::employeeUser('P-EIC');
    }

    private function assertUnchanged(ECallStatus $status): void
    {
        $this->assertSame($status, $this->eCall->refresh()->status);
        $this->assertSame(0, ApprovalRecord::query()->count());
    }

    /**
     * @return array<string, array{ECallStatus}>
     */
    public static function cancellableStatuses(): array
    {
        return [
            'for vendor assessment' => [ECallStatus::ForVendorAssessment],
            'for eic review' => [ECallStatus::ForEicReview],
            'returned' => [ECallStatus::Returned],
        ];
    }

    #[DataProvider('cancellableStatuses')]
    public function test_the_requestor_cancels_and_one_approval_record_is_written(ECallStatus $status): void
    {
        $this->setStatus($status);
        $this->actingAs($this->requestor);

        $this->cancel(['remarks' => 'Fixed it ourselves.'])
            ->assertOk()
            ->assertJsonPath('data.id', $this->eCall->id)
            ->assertJsonPath('data.status', 'cancelled');

        $this->assertSame(ECallStatus::Cancelled, $this->eCall->refresh()->status);

        $record = ApprovalRecord::query()->sole();
        $this->assertSame('e_call', $record->subject_type);
        $this->assertSame($this->eCall->id, $record->subject_id);
        $this->assertSame(ApprovalAction::Cancel, $record->action);
        $this->assertSame($this->requestor->id, $record->actor_user_id);
        $this->assertSame('P-REQ', $record->actor_employee_id);
        $this->assertSame('Fixed it ourselves.', $record->remarks);
        $this->assertSame($status->value, $record->previous_status);
        $this->assertSame('cancelled', $record->resulting_status);
    }

    #[DataProvider('cancellableStatuses')]
    public function test_the_current_site_eic_cancels(ECallStatus $status): void
    {
        $this->setStatus($status);
        $eic = $this->eic();
        $this->actingAs($eic);

        $this->cancel()->assertOk()->assertJsonPath('data.status', 'cancelled');

        $record = ApprovalRecord::query()->sole();
        $this->assertSame($eic->id, $record->actor_user_id);
        $this->assertSame('P-EIC', $record->actor_employee_id);
    }

    public function test_the_cancel_shows_in_the_approval_history(): void
    {
        $this->actingAs($this->requestor);
        $this->cancel(['remarks' => 'Duplicate of another E-Call.'])->assertOk();

        $this->getJson("/api/e-calls/{$this->eCall->id}/approvals")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.action', 'cancel')
            ->assertJsonPath('data.0.remarks', 'Duplicate of another E-Call.')
            ->assertJsonPath('data.0.previous_status', 'for_vendor_assessment')
            ->assertJsonPath('data.0.resulting_status', 'cancelled');
    }

    public function test_a_new_site_eic_cancels_and_the_replaced_eic_gets_403(): void
    {
        SiteSetting::query()->where('site_id', 'S-1')->update(['eic_employee_id' => 'P-EIC2']);

        $this->actingAs($this->eic());
        $this->cancel()->assertForbidden();
        $this->assertUnchanged(ECallStatus::ForVendorAssessment);

        $this->actingAs(self::employeeUser('P-EIC2'));
        $this->cancel()->assertOk()->assertJsonPath('data.status', 'cancelled');
    }

    /**
     * @return array<string, array{Closure(Vendor): User}>
     */
    public static function usersWhoMayNotCancel(): array
    {
        return [
            'site vendor staff' => [fn (Vendor $vendor): User => User::factory()->vendorStaff($vendor)->create()],
            'site vendor staff with the eic employee id' => [fn (Vendor $vendor): User => self::vendorStaffWithEmployeeId('P-EIC', $vendor)],
            'another vendor staff' => [fn (Vendor $vendor): User => User::factory()->vendorStaff(Vendor::factory()->create())->create()],
            'another employee' => [fn (Vendor $vendor): User => self::employeeUser('P-OTHER')],
            'a user without an employee id' => [fn (Vendor $vendor): User => User::factory()->create(['employee_id' => null])],
        ];
    }

    /**
     * @param  Closure(Vendor): User  $makeUser
     */
    #[DataProvider('usersWhoMayNotCancel')]
    public function test_only_the_requestor_or_the_current_site_eic_may_cancel(Closure $makeUser): void
    {
        $this->actingAs($makeUser($this->siteVendor));

        $this->cancel()->assertForbidden();

        $this->assertUnchanged(ECallStatus::ForVendorAssessment);
    }

    public function test_an_outsider_gets_403_regardless_of_status_or_remarks(): void
    {
        $this->setStatus(ECallStatus::Approved);
        $this->actingAs(self::employeeUser('P-OTHER'));

        $this->cancel([])->assertForbidden();

        $this->assertUnchanged(ECallStatus::Approved);
    }

    /**
     * @return array<string, array{ECallStatus}>
     */
    public static function statusesThatCannotBeCancelled(): array
    {
        return [
            'approved' => [ECallStatus::Approved],
            'closed' => [ECallStatus::Closed],
            'rejected' => [ECallStatus::Rejected],
            'cancelled' => [ECallStatus::Cancelled],
        ];
    }

    #[DataProvider('statusesThatCannotBeCancelled')]
    public function test_cancelling_after_the_eic_decided_returns_422(ECallStatus $status): void
    {
        $this->setStatus($status);

        foreach ([$this->requestor, $this->eic()] as $user) {
            $this->actingAs($user);
            $this->cancel()->assertUnprocessable()->assertJsonValidationErrors(['e_call']);
        }

        $this->assertUnchanged($status);
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
        foreach ([$this->requestor, $this->eic()] as $user) {
            $this->actingAs($user);
            $this->cancel($payload)->assertUnprocessable()->assertJsonValidationErrors(['remarks']);
        }

        $this->assertUnchanged(ECallStatus::ForVendorAssessment);
    }

    public function test_a_cancelled_e_call_accepts_no_fsr_revision_and_no_eic_decision(): void
    {
        $this->actingAs($this->requestor);
        $this->cancel()->assertOk();

        $item = PriceListItem::factory()->create(['vendor_id' => $this->siteVendor->id]);
        $this->actingAs(User::factory()->vendorStaff($this->siteVendor)->create());
        $this->post("/api/e-calls/{$this->eCall->id}/proposal", [
            'fsr' => UploadedFile::fake()->create('fsr.pdf', 120, 'application/pdf'),
            'assessment_notes' => 'Too late.',
            'lines' => [['price_list_item_id' => $item->id, 'quantity' => '1']],
        ], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->assertDatabaseCount('e_call_proposals', 0);

        $this->actingAs($this->eic());
        foreach (['approve' => [], 'return' => ['remarks' => 'x'], 'reject' => ['remarks' => 'x']] as $action => $payload) {
            $this->postJson("/api/e-calls/{$this->eCall->id}/{$action}", $payload)->assertUnprocessable();
        }

        $this->assertSame(ECallStatus::Cancelled, $this->eCall->refresh()->status);
        $this->assertSame(1, ApprovalRecord::query()->count());
    }

    public function test_a_cancelled_e_call_leaves_the_waiting_list(): void
    {
        $vendorStaff = User::factory()->vendorStaff($this->siteVendor)->create();
        $this->actingAs($vendorStaff);
        $this->assertSame([$this->eCall->id], array_column($this->getJson('/api/me/waiting')->assertOk()->json('data'), 'id'));

        $this->actingAs($this->requestor);
        $this->cancel()->assertOk();

        foreach ([$vendorStaff, $this->requestor, $this->eic()] as $user) {
            $this->actingAs($user);
            $this->getJson('/api/me/waiting')->assertOk()->assertJsonCount(0, 'data');
        }
    }

    public function test_cancelling_an_e_call_raised_from_a_pm_task_keeps_the_link_and_leaves_the_task_alone(): void
    {
        $pmTask = PmTask::factory()->create();
        $this->eCall->update(['pm_task_id' => $pmTask->id]);
        $taskBefore = $pmTask->refresh()->getAttributes();

        $this->actingAs($this->requestor);
        $this->cancel()->assertOk();

        $this->assertSame($pmTask->id, $this->eCall->refresh()->pm_task_id);
        $this->assertSame($taskBefore, $pmTask->refresh()->getAttributes());
    }

    public function test_a_status_changed_after_the_request_check_returns_422(): void
    {
        $this->setStatus(ECallStatus::ForEicReview);
        $this->eCall->refresh();
        $this->actingAs($this->eic());

        $changed = false;
        DB::listen(function ($query) use (&$changed): void {
            if ($changed || ! str_contains($query->sql, 'site_settings') || ! str_contains($query->sql, 'eic_employee_id')) {
                return;
            }

            // Another request approves the E-Call between the policy check and this request's lock.
            $changed = true;
            DB::table('e_calls')->where('id', $this->eCall->id)->update(['status' => ECallStatus::Approved->value]);
        });

        $this->cancel()->assertUnprocessable()->assertJsonValidationErrors(['e_call']);

        $this->assertTrue($changed);
        $this->assertUnchanged(ECallStatus::Approved);
    }

    public function test_the_model_rechecks_the_actor_inside_the_lock(): void
    {
        SiteSetting::query()->where('site_id', 'S-1')->update(['eic_employee_id' => 'P-EIC2']);

        try {
            ECall::cancel($this->eCall, $this->eic(), 'Too late, I was replaced.');
            $this->fail('A replaced EIC may not cancel.');
        } catch (AuthorizationException) {
            // Expected.
        }

        $this->assertUnchanged(ECallStatus::ForVendorAssessment);
    }

    public function test_cancel_returns_401_when_unauthenticated_and_404_for_an_unknown_e_call(): void
    {
        $this->cancel()->assertUnauthorized();

        $this->actingAs($this->requestor);
        $this->postJson('/api/e-calls/999999/cancel', ['remarks' => 'x'])->assertNotFound();
    }
}
