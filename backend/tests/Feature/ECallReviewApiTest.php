<?php

namespace Tests\Feature;

use App\Enums\ApprovalAction;
use App\Enums\ECallStatus;
use App\Models\ApprovalRecord;
use App\Models\ECall;
use App\Models\ECallProposal;
use App\Models\PriceListItem;
use App\Models\SiteSetting;
use App\Models\User;
use App\Models\Vendor;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ECallReviewApiTest extends TestCase
{
    use RefreshDatabase;

    private Vendor $siteVendor;

    private User $requestor;

    private ECall $eCall;

    private ECallProposal $firstRevision;

    private ECallProposal $latestRevision;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-22 10:00:00');
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
            'status' => ECallStatus::ForEicReview,
        ]);
        $this->firstRevision = $this->proposal(1);
        $this->latestRevision = $this->proposal(2);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function proposal(int $revision): ECallProposal
    {
        return $this->eCall->proposals()->create([
            'revision' => $revision,
            'assessment_notes' => "Assessment revision {$revision}.",
            'fsr_disk' => 'local',
            'fsr_path' => "e-calls/{$this->eCall->id}/proposals/fsr-{$revision}.pdf",
            'fsr_original_name' => "fsr-{$revision}.pdf",
            'fsr_mime_type' => 'application/pdf',
            'fsr_size_bytes' => 100,
            'total_millicentavos' => 1_000_000 * $revision,
            'total_centavos' => 1_000 * $revision,
            'submitted_by_user_id' => User::factory()->vendorStaff($this->siteVendor)->create()->id,
            'submitted_at' => now(),
        ]);
    }

    private function actingAsEic(string $employeeId = 'P-EIC'): User
    {
        $eic = self::employeeUser($employeeId);
        $this->actingAs($eic);

        return $eic;
    }

    private function replaceSiteEic(?string $newEmployeeId): void
    {
        SiteSetting::query()->where('site_id', 'S-1')->update(['eic_employee_id' => $newEmployeeId]);
    }

    /**
     * @return array<string, array{string, array<string, string>, ECallStatus, ApprovalAction}>
     */
    public static function actions(): array
    {
        return [
            'approve' => ['approve', [], ECallStatus::Approved, ApprovalAction::Approve],
            'return' => ['return', ['remarks' => 'Quote is too high; re-check the paint quantity.'], ECallStatus::Returned, ApprovalAction::Return],
            'reject' => ['reject', ['remarks' => 'Not a valid request.'], ECallStatus::Rejected, ApprovalAction::Reject],
        ];
    }

    /**
     * @param  array<string, string>  $payload
     */
    #[DataProvider('actions')]
    public function test_the_current_eic_acts_and_an_approval_record_is_written(string $action, array $payload, ECallStatus $expectedStatus, ApprovalAction $expectedAction): void
    {
        $eic = $this->actingAsEic();

        $this->postJson("/api/e-calls/{$this->eCall->id}/{$action}", $payload)
            ->assertOk()
            ->assertJsonPath('data.id', $this->eCall->id)
            ->assertJsonPath('data.status', $expectedStatus->value);

        $this->assertSame($expectedStatus, $this->eCall->refresh()->status);

        $record = ApprovalRecord::query()->sole();
        $this->assertSame('e_call', $record->subject_type);
        $this->assertSame($this->eCall->id, $record->subject_id);
        $this->assertSame($expectedAction, $record->action);
        $this->assertSame($eic->id, $record->actor_user_id);
        $this->assertSame('P-EIC', $record->actor_employee_id);
        $this->assertSame($payload['remarks'] ?? null, $record->remarks);
        $this->assertSame('for_eic_review', $record->previous_status);
        $this->assertSame($expectedStatus->value, $record->resulting_status);
    }

    public function test_approve_records_the_latest_proposal_revision(): void
    {
        $this->actingAsEic();

        $this->postJson("/api/e-calls/{$this->eCall->id}/approve", ['remarks' => 'Go ahead.'])
            ->assertOk()
            ->assertJsonPath('data.approved_proposal.id', $this->latestRevision->id)
            ->assertJsonPath('data.approved_proposal.revision', 2);

        $this->assertSame($this->latestRevision->id, $this->eCall->refresh()->approved_proposal_id);

        $this->getJson("/api/e-calls/{$this->eCall->id}")
            ->assertOk()
            ->assertJsonPath('data.approved_proposal.id', $this->latestRevision->id);

        $this->getJson("/api/e-calls/{$this->eCall->id}/proposals")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.1.revision', 1)
            ->assertJsonPath('data.1.assessment_notes', 'Assessment revision 1.');
    }

    public function test_return_and_reject_do_not_record_an_approved_proposal(): void
    {
        $this->actingAsEic();

        $this->postJson("/api/e-calls/{$this->eCall->id}/return", ['remarks' => 'Redo.'])
            ->assertOk()
            ->assertJsonPath('data.approved_proposal', null);

        $this->assertNull($this->eCall->refresh()->approved_proposal_id);
    }

    public function test_an_e_call_not_yet_approved_shows_no_approved_proposal(): void
    {
        $this->actingAs($this->requestor);

        $this->getJson("/api/e-calls/{$this->eCall->id}")
            ->assertOk()
            ->assertJsonPath('data.approved_proposal', null);
    }

    public function test_approve_remarks_are_optional(): void
    {
        $this->actingAsEic();

        $this->postJson("/api/e-calls/{$this->eCall->id}/approve")->assertOk();

        $this->assertNull(ApprovalRecord::query()->sole()->remarks);
    }

    /**
     * @return array<string, array{string, array<string, mixed>}>
     */
    public static function missingRemarks(): array
    {
        return [
            'return without remarks' => ['return', []],
            'return with empty remarks' => ['return', ['remarks' => '']],
            'return with whitespace remarks' => ['return', ['remarks' => '   ']],
            'reject without remarks' => ['reject', []],
            'reject with empty remarks' => ['reject', ['remarks' => '']],
            'reject with non-string remarks' => ['reject', ['remarks' => ['no']]],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    #[DataProvider('missingRemarks')]
    public function test_return_and_reject_without_remarks_return_422(string $action, array $payload): void
    {
        $this->actingAsEic();

        $this->postJson("/api/e-calls/{$this->eCall->id}/{$action}", $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['remarks']);

        $this->assertSame(ECallStatus::ForEicReview, $this->eCall->refresh()->status);
        $this->assertSame(0, ApprovalRecord::query()->count());
    }

    /**
     * @return array<string, array{Closure(): User}>
     */
    public static function usersWhoAreNotTheCurrentEic(): array
    {
        return [
            'another employee' => [fn (): User => self::employeeUser('P-OTHER')],
            'a user with no employee id' => [fn (): User => User::factory()->create()],
            'staff of the site vendor' => [fn (): User => self::vendorStaffWithEmployeeId('P-EIC', Vendor::query()->orderBy('id')->firstOrFail())],
            'the requestor' => [fn (): User => User::query()->where('employee_id', 'P-REQ')->firstOrFail()],
        ];
    }

    /**
     * @param  Closure(): User  $makeUser
     */
    #[DataProvider('usersWhoAreNotTheCurrentEic')]
    public function test_only_the_current_site_eic_can_act(Closure $makeUser): void
    {
        $this->actingAs($makeUser());

        $this->postJson("/api/e-calls/{$this->eCall->id}/approve")->assertForbidden();
        $this->postJson("/api/e-calls/{$this->eCall->id}/return", ['remarks' => 'x'])->assertForbidden();
        $this->postJson("/api/e-calls/{$this->eCall->id}/reject", ['remarks' => 'x'])->assertForbidden();

        $this->assertSame(ECallStatus::ForEicReview, $this->eCall->refresh()->status);
        $this->assertSame(0, ApprovalRecord::query()->count());
    }

    public function test_a_non_eic_gets_403_before_remarks_are_validated(): void
    {
        $this->actingAs(self::employeeUser('P-OTHER'));

        $this->postJson("/api/e-calls/{$this->eCall->id}/reject")->assertForbidden();
    }

    /**
     * @param  array<string, string>  $payload
     */
    #[DataProvider('actions')]
    public function test_after_the_site_eic_is_replaced_the_new_eic_can_act(string $action, array $payload, ECallStatus $expectedStatus, ApprovalAction $expectedAction): void
    {
        $this->replaceSiteEic('P-NEW');
        $newEic = $this->actingAsEic('P-NEW');

        $this->postJson("/api/e-calls/{$this->eCall->id}/{$action}", $payload)->assertOk();

        $this->eCall->refresh();
        $this->assertSame($expectedStatus, $this->eCall->status);
        $this->assertSame('P-EIC', $this->eCall->eic_employee_id, 'The EIC captured at filing stays as history.');
        $record = ApprovalRecord::query()->sole();
        $this->assertSame($newEic->id, $record->actor_user_id);
        $this->assertSame($expectedAction, $record->action);
    }

    public function test_a_replaced_eic_gets_403_even_though_captured_at_filing(): void
    {
        $this->replaceSiteEic('P-NEW');
        $this->actingAsEic('P-EIC');

        $this->postJson("/api/e-calls/{$this->eCall->id}/approve")->assertForbidden();
        $this->postJson("/api/e-calls/{$this->eCall->id}/return", ['remarks' => 'x'])->assertForbidden();
        $this->postJson("/api/e-calls/{$this->eCall->id}/reject", ['remarks' => 'x'])->assertForbidden();

        $this->assertSame(ECallStatus::ForEicReview, $this->eCall->refresh()->status);
        $this->assertSame(0, ApprovalRecord::query()->count());
    }

    public function test_nobody_can_act_when_the_site_has_no_current_eic(): void
    {
        $this->replaceSiteEic(null);
        $this->actingAsEic('P-EIC');

        $this->postJson("/api/e-calls/{$this->eCall->id}/approve")->assertForbidden();
    }

    public function test_nobody_can_act_when_the_site_has_no_settings_row(): void
    {
        SiteSetting::query()->where('site_id', 'S-1')->delete();
        $this->actingAsEic('P-EIC');

        $this->postJson("/api/e-calls/{$this->eCall->id}/approve")->assertForbidden();
    }

    /**
     * @return array<string, array{ECallStatus}>
     */
    public static function statusesOtherThanForEicReview(): array
    {
        return [
            'for vendor assessment' => [ECallStatus::ForVendorAssessment],
            'returned' => [ECallStatus::Returned],
            'approved' => [ECallStatus::Approved],
            'closed' => [ECallStatus::Closed],
            'rejected' => [ECallStatus::Rejected],
            'cancelled' => [ECallStatus::Cancelled],
        ];
    }

    #[DataProvider('statusesOtherThanForEicReview')]
    public function test_actions_outside_for_eic_review_return_422(ECallStatus $status): void
    {
        $this->eCall->update(['status' => $status]);
        $this->actingAsEic();

        foreach (['approve' => [], 'return' => ['remarks' => 'x'], 'reject' => ['remarks' => 'x']] as $action => $payload) {
            $this->postJson("/api/e-calls/{$this->eCall->id}/{$action}", $payload)
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['e_call']);
        }

        $this->assertSame($status, $this->eCall->refresh()->status);
        $this->assertSame(0, ApprovalRecord::query()->count());
    }

    public function test_after_a_reject_every_further_action_returns_422(): void
    {
        $this->actingAsEic();
        $this->postJson("/api/e-calls/{$this->eCall->id}/reject", ['remarks' => 'Not needed.'])->assertOk();

        foreach (['approve' => [], 'return' => ['remarks' => 'x'], 'reject' => ['remarks' => 'x']] as $action => $payload) {
            $this->postJson("/api/e-calls/{$this->eCall->id}/{$action}", $payload)->assertUnprocessable();
        }

        $item = PriceListItem::factory()->create(['vendor_id' => $this->siteVendor->id]);
        $this->actingAs(User::factory()->vendorStaff($this->siteVendor)->create());
        $this->post("/api/e-calls/{$this->eCall->id}/proposal", [
            'fsr' => UploadedFile::fake()->create('fsr.pdf', 10, 'application/pdf'),
            'assessment_notes' => 'Trying again.',
            'lines' => [['price_list_item_id' => $item->id, 'quantity' => '1']],
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['e_call']);

        $this->assertSame(ECallStatus::Rejected, $this->eCall->refresh()->status);
        $this->assertSame(1, ApprovalRecord::query()->count());
        $this->assertSame(2, $this->eCall->proposals()->count());
    }

    public function test_after_a_return_the_vendor_resubmits_and_the_eic_reviews_again(): void
    {
        $this->actingAsEic();
        $this->postJson("/api/e-calls/{$this->eCall->id}/return", ['remarks' => 'Fewer posts.'])->assertOk();

        $item = PriceListItem::factory()->create(['vendor_id' => $this->siteVendor->id]);
        $this->actingAs(User::factory()->vendorStaff($this->siteVendor)->create());
        $this->post("/api/e-calls/{$this->eCall->id}/proposal", [
            'fsr' => UploadedFile::fake()->create('fsr-3.pdf', 10, 'application/pdf'),
            'assessment_notes' => 'Revised.',
            'lines' => [['price_list_item_id' => $item->id, 'quantity' => '1']],
        ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.revision', 3);

        $this->actingAsEic();
        $this->postJson("/api/e-calls/{$this->eCall->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.approved_proposal.revision', 3);

        $this->assertSame(
            ['return', 'approve'],
            ApprovalRecord::query()->orderBy('id')->get()->map(fn (ApprovalRecord $record): string => $record->action->value)->all(),
        );
    }

    /**
     * @return array<string, array{string, array<string, string>}>
     */
    public static function actionRequests(): array
    {
        return array_map(fn (array $case): array => [$case[0], $case[1]], self::actions());
    }

    /**
     * @param  array<string, string>  $payload
     */
    #[DataProvider('actionRequests')]
    public function test_an_eic_replaced_after_the_policy_check_cannot_act(string $action, array $payload): void
    {
        $this->actingAsEic();

        $replaced = false;
        DB::listen(function ($query) use (&$replaced): void {
            if ($replaced || ! str_contains($query->sql, 'site_settings') || ! str_contains($query->sql, 'eic_employee_id')) {
                return;
            }

            // The policy has just read the current EIC; an admin replaces the EIC before the action commits.
            $replaced = true;
            $this->replaceSiteEic('P-NEW');
        });

        $this->postJson("/api/e-calls/{$this->eCall->id}/{$action}", $payload)->assertForbidden();

        $this->assertTrue($replaced);
        $this->assertSame(ECallStatus::ForEicReview, $this->eCall->refresh()->status);
        $this->assertSame(0, ApprovalRecord::query()->count());
    }

    /**
     * @param  array<string, string>  $payload
     */
    #[DataProvider('actionRequests')]
    public function test_a_status_changed_after_the_request_check_returns_422(string $action, array $payload): void
    {
        $this->actingAsEic();

        $changed = false;
        DB::listen(function ($query) use (&$changed): void {
            if ($changed || ! str_contains($query->sql, 'site_settings') || ! str_contains($query->sql, 'eic_employee_id')) {
                return;
            }

            // Another request moves the E-Call on between the policy check and this request's lock.
            $changed = true;
            DB::table('e_calls')->where('id', $this->eCall->id)->update(['status' => ECallStatus::Returned->value]);
        });

        $this->postJson("/api/e-calls/{$this->eCall->id}/{$action}", $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['e_call']);

        $this->assertTrue($changed);
        $this->assertSame(ECallStatus::Returned, $this->eCall->refresh()->status);
        $this->assertSame(0, ApprovalRecord::query()->count());
    }

    public function test_the_model_rechecks_the_current_eic_without_the_policy(): void
    {
        $this->replaceSiteEic('P-NEW');
        $replacedEic = self::employeeUser('P-EIC');

        try {
            ECall::approveProposal($this->eCall, $replacedEic, null);
            $this->fail('A replaced EIC must not be able to approve, even when called directly.');
        } catch (AuthorizationException) {
            // Expected.
        }

        $this->assertSame(ECallStatus::ForEicReview, $this->eCall->refresh()->status);
        $this->assertNull($this->eCall->approved_proposal_id);
        $this->assertSame(0, ApprovalRecord::query()->count());
    }

    public function test_the_approval_history_is_listed_oldest_first(): void
    {
        $eic = $this->actingAsEic();
        $this->postJson("/api/e-calls/{$this->eCall->id}/return", ['remarks' => 'Fewer posts.'])->assertOk();
        $this->eCall->refresh()->update(['status' => ECallStatus::ForEicReview]);
        Carbon::setTestNow('2026-11-23 09:00:00');
        $this->postJson("/api/e-calls/{$this->eCall->id}/approve", ['remarks' => 'OK.'])->assertOk();

        $this->getJson("/api/e-calls/{$this->eCall->id}/approvals")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.action', 'return')
            ->assertJsonPath('data.0.remarks', 'Fewer posts.')
            ->assertJsonPath('data.0.previous_status', 'for_eic_review')
            ->assertJsonPath('data.0.resulting_status', 'returned')
            ->assertJsonPath('data.0.actor.user_id', $eic->id)
            ->assertJsonPath('data.0.created_at', '2026-11-22T02:00:00.000000Z')
            ->assertJsonPath('data.1.action', 'approve')
            ->assertJsonPath('data.1.resulting_status', 'approved')
            ->assertJsonPath('data.1.created_at', '2026-11-23T01:00:00.000000Z');
    }

    /**
     * @return array<string, array{Closure(): User}>
     */
    public static function usersWhoCanViewTheECall(): array
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
    #[DataProvider('usersWhoCanViewTheECall')]
    public function test_anyone_who_can_view_the_e_call_reads_the_approval_history(Closure $makeUser): void
    {
        $this->actingAsEic();
        $this->postJson("/api/e-calls/{$this->eCall->id}/return", ['remarks' => 'x'])->assertOk();

        $this->actingAs($makeUser());

        $this->getJson("/api/e-calls/{$this->eCall->id}/approvals")->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_staff_of_another_vendor_cannot_read_the_approval_history(): void
    {
        $this->actingAs(User::factory()->vendorStaff()->create());

        $this->getJson("/api/e-calls/{$this->eCall->id}/approvals")->assertForbidden();
    }

    public function test_the_morph_map_has_a_stable_e_call_alias(): void
    {
        $this->assertSame(ECall::class, Relation::getMorphedModel('e_call'));
        $this->assertSame('e_call', $this->eCall->getMorphClass());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function allEndpoints(): array
    {
        return [
            'approve' => ['post', 'approve'],
            'return' => ['post', 'return'],
            'reject' => ['post', 'reject'],
            'approvals' => ['get', 'approvals'],
        ];
    }

    #[DataProvider('allEndpoints')]
    public function test_every_endpoint_returns_401_when_unauthenticated(string $method, string $path): void
    {
        $this->json($method, "/api/e-calls/{$this->eCall->id}/{$path}", ['remarks' => 'x'])->assertUnauthorized();
    }
}
