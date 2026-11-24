<?php

namespace Tests\Feature;

use App\Enums\ApprovalAction;
use App\Enums\PmStatus;
use App\Events\PmTaskCompleted;
use App\Models\ApprovalRecord;
use App\Models\PmTask;
use App\Models\SiteSetting;
use App\Models\User;
use App\Models\Vendor;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PmTaskValidationApiTest extends TestCase
{
    use RefreshDatabase;

    private Vendor $vendor;

    private PmTask $task;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-22 10:00:00');
        $this->vendor = Vendor::factory()->create();
        SiteSetting::factory()->create(['site_id' => 'S-1', 'vendor_id' => $this->vendor->id, 'eic_employee_id' => 'P-EIC']);
        $this->task = PmTask::factory()->create([
            'site_id' => 'S-1',
            'vendor_id' => $this->vendor->id,
            'eic_employee_id' => 'P-EIC',
            'equipment_id' => 'EQ-1',
            'status' => PmStatus::ForValidation,
            'work_done_date' => '2026-11-20',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function actingAsEic(): User
    {
        $eic = User::factory()->create(['employee_id' => 'P-EIC']);
        $this->actingAs($eic);

        return $eic;
    }

    public function test_the_eic_approves_a_task_in_for_validation(): void
    {
        Event::fake([PmTaskCompleted::class]);
        $eic = $this->actingAsEic();

        $this->postJson("/api/pm-tasks/{$this->task->id}/approve", ['remarks' => 'Good work.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.work_done_date', '2026-11-20');

        $this->task->refresh();
        $this->assertSame(PmStatus::Completed, $this->task->status);
        $this->assertSame('2026-11-20', $this->task->work_done_date->toDateString());
        $this->assertNull(DB::table('pm_tasks')->where('id', $this->task->id)->value('open_equipment_id'), 'The open-task key is released.');

        $record = ApprovalRecord::query()->sole();
        $this->assertSame(ApprovalAction::Approve, $record->action);
        $this->assertSame($eic->id, $record->actor_user_id);
        $this->assertSame('P-EIC', $record->actor_employee_id);
        $this->assertSame('Good work.', $record->remarks);
        $this->assertSame('for_validation', $record->previous_status);
        $this->assertSame('completed', $record->resulting_status);
        $this->assertTrue($record->subject->is($this->task));

        Event::assertDispatchedTimes(PmTaskCompleted::class, 1);
        Event::assertDispatched(
            PmTaskCompleted::class,
            fn (PmTaskCompleted $event): bool => $event->pmTaskId === $this->task->id
                && $event->equipmentId === 'EQ-1'
                && $event->workDoneDate->toDateString() === '2026-11-20',
        );
    }

    public function test_approve_remarks_are_optional(): void
    {
        $this->actingAsEic();

        $this->postJson("/api/pm-tasks/{$this->task->id}/approve")->assertOk();

        $this->assertNull(ApprovalRecord::query()->sole()->remarks);
    }

    public function test_approve_ignores_a_work_done_date_in_the_request(): void
    {
        $this->actingAsEic();

        $this->postJson("/api/pm-tasks/{$this->task->id}/approve", ['work_done_date' => '2026-11-21'])->assertOk();

        $this->assertSame('2026-11-20', $this->task->refresh()->work_done_date->toDateString());
    }

    public function test_the_eic_returns_a_task_with_remarks(): void
    {
        Event::fake([PmTaskCompleted::class]);
        $eic = $this->actingAsEic();

        $this->postJson("/api/pm-tasks/{$this->task->id}/return", ['remarks' => 'Coolant reading missing a photo.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'returned');

        $this->assertSame(PmStatus::Returned, $this->task->refresh()->status);

        $record = ApprovalRecord::query()->sole();
        $this->assertSame(ApprovalAction::Return, $record->action);
        $this->assertSame($eic->id, $record->actor_user_id);
        $this->assertSame('Coolant reading missing a photo.', $record->remarks);
        $this->assertSame('for_validation', $record->previous_status);
        $this->assertSame('returned', $record->resulting_status);

        Event::assertNotDispatched(PmTaskCompleted::class);
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function missingRemarks(): array
    {
        return [
            'no remarks' => [[]],
            'empty remarks' => [['remarks' => '']],
            'whitespace remarks' => [['remarks' => '   ']],
            'non-string remarks' => [['remarks' => ['a']]],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    #[DataProvider('missingRemarks')]
    public function test_return_without_remarks_returns_422(array $payload): void
    {
        $this->actingAsEic();

        $this->postJson("/api/pm-tasks/{$this->task->id}/return", $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['remarks']);

        $this->assertSame(PmStatus::ForValidation, $this->task->refresh()->status);
        $this->assertSame(0, ApprovalRecord::query()->count());
    }

    /**
     * @return array<string, array{Closure(): User}>
     */
    public static function usersWhoAreNotTheEic(): array
    {
        return [
            'another employee' => [fn (): User => User::factory()->create(['employee_id' => 'P-OTHER'])],
            'a user with no employee id' => [fn (): User => User::factory()->create()],
            'staff of the tasks vendor' => [fn (): User => User::factory()->vendorStaff(Vendor::query()->firstOrFail())->create()],
        ];
    }

    /**
     * @param  Closure(): User  $makeUser
     */
    #[DataProvider('usersWhoAreNotTheEic')]
    public function test_only_the_tasks_eic_can_approve_or_return(Closure $makeUser): void
    {
        $this->actingAs($makeUser());

        $this->postJson("/api/pm-tasks/{$this->task->id}/approve")->assertForbidden();
        $this->postJson("/api/pm-tasks/{$this->task->id}/return", ['remarks' => 'No.'])->assertForbidden();

        $this->assertSame(PmStatus::ForValidation, $this->task->refresh()->status);
        $this->assertSame(0, ApprovalRecord::query()->count());
    }

    private function replaceSiteEic(string $newEmployeeId): void
    {
        SiteSetting::query()->where('site_id', 'S-1')->update(['eic_employee_id' => $newEmployeeId]);
    }

    public function test_after_the_site_eic_is_replaced_the_new_eic_can_approve(): void
    {
        Event::fake([PmTaskCompleted::class]);
        $this->replaceSiteEic('P-NEW');
        $newEic = User::factory()->create(['employee_id' => 'P-NEW']);
        $this->actingAs($newEic);

        $this->postJson("/api/pm-tasks/{$this->task->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');

        $record = ApprovalRecord::query()->sole();
        $this->assertSame($newEic->id, $record->actor_user_id);
        $this->assertSame('P-NEW', $record->actor_employee_id);
        $this->assertSame('P-EIC', $this->task->refresh()->eic_employee_id, 'The captured EIC stays on the task as history.');
        Event::assertDispatched(PmTaskCompleted::class);
    }

    public function test_after_the_site_eic_is_replaced_the_new_eic_can_return(): void
    {
        $this->replaceSiteEic('P-NEW');
        $this->actingAs(User::factory()->create(['employee_id' => 'P-NEW']));

        $this->postJson("/api/pm-tasks/{$this->task->id}/return", ['remarks' => 'Redo the coolant check.'])->assertOk();

        $this->assertSame(PmStatus::Returned, $this->task->refresh()->status);
        $this->assertSame('P-NEW', ApprovalRecord::query()->sole()->actor_employee_id);
    }

    public function test_a_replaced_eic_gets_403_even_though_captured_on_the_task(): void
    {
        $this->replaceSiteEic('P-NEW');
        $this->actingAsEic();

        $this->postJson("/api/pm-tasks/{$this->task->id}/approve")->assertForbidden();
        $this->postJson("/api/pm-tasks/{$this->task->id}/return", ['remarks' => 'No.'])->assertForbidden();

        $this->assertSame(PmStatus::ForValidation, $this->task->refresh()->status);
        $this->assertSame(0, ApprovalRecord::query()->count());
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function sitesWithoutACurrentEic(): array
    {
        return [
            'eic removed from the site' => [['eic_employee_id' => null]],
        ];
    }

    #[DataProvider('sitesWithoutACurrentEic')]
    public function test_nobody_can_validate_when_the_site_has_no_current_eic(array $change): void
    {
        SiteSetting::query()->where('site_id', 'S-1')->update($change);
        $this->actingAsEic();

        $this->postJson("/api/pm-tasks/{$this->task->id}/approve")->assertForbidden();
    }

    public function test_nobody_can_validate_when_the_site_has_no_settings_row(): void
    {
        SiteSetting::query()->where('site_id', 'S-1')->delete();
        $this->actingAsEic();

        $this->postJson("/api/pm-tasks/{$this->task->id}/approve")->assertForbidden();
    }

    private function vendorUserWithTheEicEmployeeId(): User
    {
        return User::factory()->vendorStaff($this->vendor)->create(['employee_id' => 'P-EIC']);
    }

    public function test_vendor_staff_with_the_eic_employee_id_gets_403_on_approve_and_return(): void
    {
        Event::fake([PmTaskCompleted::class]);
        $this->actingAs($this->vendorUserWithTheEicEmployeeId());

        $this->postJson("/api/pm-tasks/{$this->task->id}/approve")->assertForbidden();
        $this->postJson("/api/pm-tasks/{$this->task->id}/return", ['remarks' => 'No.'])->assertForbidden();

        $this->assertSame(PmStatus::ForValidation, $this->task->refresh()->status);
        $this->assertSame(0, ApprovalRecord::query()->count());
        Event::assertNotDispatched(PmTaskCompleted::class);
    }

    public function test_the_in_lock_recheck_refuses_vendor_staff_with_the_eic_employee_id(): void
    {
        $vendorUser = $this->vendorUserWithTheEicEmployeeId();

        foreach ([
            fn () => PmTask::approveReport($this->task, $vendorUser, null),
            fn () => PmTask::returnReport($this->task, $vendorUser, 'No.'),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('Vendor staff must not be able to act as the EIC.');
            } catch (AuthorizationException) {
                // Expected.
            }
        }

        $this->assertSame(PmStatus::ForValidation, $this->task->refresh()->status);
        $this->assertSame(0, ApprovalRecord::query()->count());
    }

    public function test_a_user_with_no_employee_id_gets_403_even_when_the_site_has_no_eic(): void
    {
        SiteSetting::query()->where('site_id', 'S-1')->update(['eic_employee_id' => null]);
        $this->actingAs(User::factory()->create(['employee_id' => null]));

        $this->postJson("/api/pm-tasks/{$this->task->id}/approve")->assertForbidden();
    }

    /**
     * @return array<string, array{string, array<string, string>}>
     */
    public static function validationActions(): array
    {
        return [
            'approve' => ['approve', []],
            'return' => ['return', ['remarks' => 'Redo it.']],
        ];
    }

    /**
     * @param  array<string, string>  $payload
     */
    #[DataProvider('validationActions')]
    public function test_an_eic_replaced_after_the_policy_check_cannot_act(string $action, array $payload): void
    {
        Event::fake([PmTaskCompleted::class]);
        $this->actingAsEic();

        $replaced = false;
        DB::listen(function ($query) use (&$replaced): void {
            if ($replaced || ! str_contains($query->sql, 'site_settings') || ! str_contains($query->sql, 'eic_employee_id')) {
                return;
            }

            // The policy check has just read the current EIC; an admin replaces the EIC before the action commits.
            $replaced = true;
            $this->replaceSiteEic('P-NEW');
        });

        $this->postJson("/api/pm-tasks/{$this->task->id}/{$action}", $payload)->assertForbidden();

        $this->assertTrue($replaced);
        $this->assertSame(PmStatus::ForValidation, $this->task->refresh()->status);
        $this->assertSame(0, ApprovalRecord::query()->count());
        Event::assertNotDispatched(PmTaskCompleted::class);
    }

    public function test_the_model_rechecks_the_current_eic_without_the_policy(): void
    {
        $this->replaceSiteEic('P-NEW');
        $replacedEic = User::factory()->create(['employee_id' => 'P-EIC']);

        try {
            PmTask::approveReport($this->task, $replacedEic, null);
            $this->fail('A replaced EIC must not be able to approve, even when called directly.');
        } catch (AuthorizationException) {
            // Expected.
        }

        $this->assertSame(PmStatus::ForValidation, $this->task->refresh()->status);
        $this->assertSame(0, ApprovalRecord::query()->count());
    }

    public function test_the_replaced_and_the_current_eic_both_read_the_approval_history(): void
    {
        $this->actingAsEic();
        $this->postJson("/api/pm-tasks/{$this->task->id}/return", ['remarks' => 'Fix it.'])->assertOk();
        $this->replaceSiteEic('P-NEW');

        $this->getJson("/api/pm-tasks/{$this->task->id}/approvals")->assertOk()->assertJsonCount(1, 'data');

        $this->actingAs(User::factory()->create(['employee_id' => 'P-NEW']));
        $this->getJson("/api/pm-tasks/{$this->task->id}/approvals")->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_a_non_eic_gets_403_even_without_remarks(): void
    {
        $this->actingAs(User::factory()->create(['employee_id' => 'P-OTHER']));

        $this->postJson("/api/pm-tasks/{$this->task->id}/return")->assertForbidden();
    }

    /**
     * @return array<string, array{PmStatus}>
     */
    public static function statusesOtherThanForValidation(): array
    {
        return [
            'scheduled' => [PmStatus::Scheduled],
            'in progress' => [PmStatus::InProgress],
            'returned' => [PmStatus::Returned],
            'completed' => [PmStatus::Completed],
            'cancelled' => [PmStatus::Cancelled],
        ];
    }

    #[DataProvider('statusesOtherThanForValidation')]
    public function test_approve_or_return_outside_for_validation_returns_422(PmStatus $status): void
    {
        Event::fake([PmTaskCompleted::class]);
        $this->task->update(['status' => $status]);
        $this->actingAsEic();

        $this->postJson("/api/pm-tasks/{$this->task->id}/approve")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['pm_task']);
        $this->postJson("/api/pm-tasks/{$this->task->id}/return", ['remarks' => 'No.'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['pm_task']);

        $this->assertSame($status, $this->task->refresh()->status);
        $this->assertSame(0, ApprovalRecord::query()->count());
        Event::assertNotDispatched(PmTaskCompleted::class);
    }

    public function test_approving_twice_writes_one_record(): void
    {
        $this->actingAsEic();

        $this->postJson("/api/pm-tasks/{$this->task->id}/approve")->assertOk();
        $this->postJson("/api/pm-tasks/{$this->task->id}/approve")->assertUnprocessable();

        $this->assertSame(1, ApprovalRecord::query()->count());
    }

    public function test_there_is_no_reject_endpoint(): void
    {
        $this->actingAsEic();

        $this->postJson("/api/pm-tasks/{$this->task->id}/reject", ['remarks' => 'No.'])->assertNotFound();

        $this->assertSame(PmStatus::ForValidation, $this->task->refresh()->status);
    }

    public function test_vendor_start_and_submit_write_no_approval_records(): void
    {
        Storage::fake(config('pss.e_call_attachments.disk'));
        $task = PmTask::factory()->create(['vendor_id' => $this->vendor->id, 'status' => PmStatus::Scheduled, 'no_checklist' => true]);
        $this->actingAs(User::factory()->vendorStaff($this->vendor)->create());

        $this->postJson("/api/pm-tasks/{$task->id}/start")->assertOk();
        $this->post("/api/pm-tasks/{$task->id}/report", [
            'work_done_date' => '2026-11-22',
            'service_report' => UploadedFile::fake()->create('report.pdf', 10, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertCreated();

        $this->assertSame(0, ApprovalRecord::query()->count());
    }

    public function test_the_approval_history_is_listed_oldest_first(): void
    {
        $eic = $this->actingAsEic();
        $this->postJson("/api/pm-tasks/{$this->task->id}/return", ['remarks' => 'Add the oil photo.'])->assertOk();
        $this->task->refresh()->update(['status' => PmStatus::ForValidation]);
        Carbon::setTestNow('2026-11-23 09:00:00');
        $this->postJson("/api/pm-tasks/{$this->task->id}/approve", ['remarks' => 'OK now.'])->assertOk();

        $this->getJson("/api/pm-tasks/{$this->task->id}/approvals")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.action', 'return')
            ->assertJsonPath('data.0.remarks', 'Add the oil photo.')
            ->assertJsonPath('data.0.previous_status', 'for_validation')
            ->assertJsonPath('data.0.resulting_status', 'returned')
            ->assertJsonPath('data.0.actor.user_id', $eic->id)
            ->assertJsonPath('data.0.actor.employee_id', 'P-EIC')
            ->assertJsonPath('data.0.created_at', '2026-11-22T02:00:00.000000Z')
            ->assertJsonPath('data.1.action', 'approve')
            ->assertJsonPath('data.1.resulting_status', 'completed')
            ->assertJsonPath('data.1.created_at', '2026-11-23T01:00:00.000000Z');
    }

    public function test_the_tasks_vendor_staff_can_read_the_approval_history(): void
    {
        $this->actingAsEic();
        $this->postJson("/api/pm-tasks/{$this->task->id}/return", ['remarks' => 'Fix it.'])->assertOk();

        $this->actingAs(User::factory()->vendorStaff($this->vendor)->create());

        $this->getJson("/api/pm-tasks/{$this->task->id}/approvals")->assertOk()->assertJsonCount(1, 'data');
    }

    /**
     * @return array<string, array{Closure(): User}>
     */
    public static function usersWhoCannotReadTheHistory(): array
    {
        return [
            'staff of another vendor' => [fn (): User => User::factory()->vendorStaff()->create()],
        ];
    }

    /**
     * One rule for approval history (issue 04, Q6): anyone who can view the task reads it.
     */
    public function test_an_internal_employee_who_is_not_the_eic_reads_the_approval_history(): void
    {
        $this->actingAsEic();
        $this->postJson("/api/pm-tasks/{$this->task->id}/return", ['remarks' => 'Fix it.'])->assertOk();

        $this->actingAs(User::factory()->create(['employee_id' => 'P-OTHER']));
        $this->getJson("/api/pm-tasks/{$this->task->id}/approvals")->assertOk()->assertJsonCount(1, 'data');
    }

    /**
     * @param  Closure(): User  $makeUser
     */
    #[DataProvider('usersWhoCannotReadTheHistory')]
    public function test_others_cannot_read_the_approval_history(Closure $makeUser): void
    {
        $this->actingAs($makeUser());

        $this->getJson("/api/pm-tasks/{$this->task->id}/approvals")->assertForbidden();
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function allEndpoints(): array
    {
        return [
            'approve' => ['post', 'approve'],
            'return' => ['post', 'return'],
            'approvals' => ['get', 'approvals'],
        ];
    }

    #[DataProvider('allEndpoints')]
    public function test_every_endpoint_returns_401_when_unauthenticated(string $method, string $path): void
    {
        $this->json($method, "/api/pm-tasks/{$this->task->id}/{$path}", ['remarks' => 'x'])->assertUnauthorized();
    }
}
