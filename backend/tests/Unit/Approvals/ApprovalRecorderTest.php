<?php

namespace Tests\Unit\Approvals;

use App\Approvals\ApprovalRecorder;
use App\Enums\ApprovalAction;
use App\Enums\PmStatus;
use App\Models\ApprovalRecord;
use App\Models\PmTask;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;
use Tests\TestCase;

/**
 * The shared Approval Record component on its own, with a PM Task as the subject. Later workflows
 * (E-Calls, Work Orders) reuse it unchanged.
 */
class ApprovalRecorderTest extends TestCase
{
    use RefreshDatabase;

    private ApprovalRecorder $recorder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->recorder = $this->app->make(ApprovalRecorder::class);
    }

    public function test_it_writes_the_new_status_and_a_record_with_every_field(): void
    {
        Carbon::setTestNow('2026-11-22 14:30:00');
        $task = PmTask::factory()->create(['status' => PmStatus::ForValidation]);
        $actor = User::factory()->create(['employee_id' => 'P-7']);

        $record = $this->recorder->transition($task, $actor, ApprovalAction::Return, PmStatus::Returned, 'Photos are blurry.');

        $this->assertSame(PmStatus::Returned, $task->refresh()->status);
        $this->assertSame(PmStatus::Returned, $record->subject->status);

        $stored = ApprovalRecord::query()->sole();
        $this->assertTrue($stored->is($record));
        $this->assertSame('pm_task', $stored->subject_type);
        $this->assertSame($task->id, $stored->subject_id);
        $this->assertSame(ApprovalAction::Return, $stored->action);
        $this->assertSame($actor->id, $stored->actor_user_id);
        $this->assertSame('P-7', $stored->actor_employee_id);
        $this->assertSame('Photos are blurry.', $stored->remarks);
        $this->assertSame('for_validation', $stored->previous_status);
        $this->assertSame('returned', $stored->resulting_status);
        $this->assertSame('2026-11-22 14:30:00', $stored->created_at->format('Y-m-d H:i:s'));
        $this->assertNull($stored->getAttribute('updated_at'));

        Carbon::setTestNow();
    }

    public function test_the_previous_status_is_read_from_the_locked_row_not_the_callers_copy(): void
    {
        $task = PmTask::factory()->create(['status' => PmStatus::ForValidation]);
        $staleCopy = PmTask::query()->findOrFail($task->id);
        $task->update(['status' => PmStatus::Returned]);

        $record = $this->recorder->transition($staleCopy, User::factory()->create(), ApprovalAction::Approve, PmStatus::Completed);

        $this->assertSame('returned', $record->previous_status);
        $this->assertSame(PmStatus::Completed, $staleCopy->status, "The caller's instance reflects the new status.");
    }

    public function test_the_actor_employee_id_is_a_snapshot(): void
    {
        $task = PmTask::factory()->create(['status' => PmStatus::ForValidation]);
        $actor = User::factory()->create(['employee_id' => 'P-7']);

        $record = $this->recorder->transition($task, $actor, ApprovalAction::Approve, PmStatus::Completed);
        $actor->forceFill(['employee_id' => 'P-99'])->save();

        $this->assertSame('P-7', $record->refresh()->actor_employee_id);
    }

    public function test_remarks_are_optional_and_a_null_employee_id_is_allowed(): void
    {
        $task = PmTask::factory()->create(['status' => PmStatus::ForValidation]);

        $record = $this->recorder->transition($task, User::factory()->create(), ApprovalAction::Approve, PmStatus::Completed);

        $this->assertNull($record->remarks);
        $this->assertNull($record->actor_employee_id);
    }

    public function test_it_rolls_back_the_record_when_the_subject_write_fails(): void
    {
        $task = PmTask::factory()->create(['status' => PmStatus::ForValidation]);
        PmTask::saving(fn (): bool => false);

        try {
            $this->recorder->transition($task, User::factory()->create(), ApprovalAction::Approve, PmStatus::Completed);
            $this->fail('The transition should have failed.');
        } catch (RuntimeException) {
            // Expected.
        }

        $this->assertSame('for_validation', DB::table('pm_tasks')->where('id', $task->id)->value('status'));
        $this->assertSame(0, ApprovalRecord::query()->count());
    }

    public function test_it_rolls_back_the_status_when_the_record_write_fails(): void
    {
        $task = PmTask::factory()->create(['status' => PmStatus::ForValidation]);
        $unsavedActor = User::factory()->make();

        try {
            $this->recorder->transition($task, $unsavedActor, ApprovalAction::Approve, PmStatus::Completed);
            $this->fail('The transition should have failed.');
        } catch (\Throwable) {
            // Expected: actor_user_id is required.
        }

        $this->assertSame('for_validation', DB::table('pm_tasks')->where('id', $task->id)->value('status'));
        $this->assertSame(0, ApprovalRecord::query()->count());
    }

    public function test_a_record_cannot_be_updated(): void
    {
        $task = PmTask::factory()->create(['status' => PmStatus::ForValidation]);
        $record = $this->recorder->transition($task, User::factory()->create(), ApprovalAction::Return, PmStatus::Returned, 'Fix it.');

        try {
            $record->update(['remarks' => 'Changed.']);
            $this->fail('Updating an Approval Record should be refused.');
        } catch (LogicException) {
            // Expected.
        }

        $this->assertSame('Fix it.', $record->refresh()->remarks);
    }

    public function test_a_record_cannot_be_deleted(): void
    {
        $task = PmTask::factory()->create(['status' => PmStatus::ForValidation]);
        $record = $this->recorder->transition($task, User::factory()->create(), ApprovalAction::Approve, PmStatus::Completed);

        try {
            $record->delete();
            $this->fail('Deleting an Approval Record should be refused.');
        } catch (LogicException) {
            // Expected.
        }

        $this->assertModelExists($record);
    }

    public function test_records_are_listed_oldest_first_with_ties_broken_by_id(): void
    {
        Carbon::setTestNow('2026-11-22 09:00:00');
        $task = PmTask::factory()->create(['status' => PmStatus::ForValidation]);
        $actor = User::factory()->create();

        $first = $this->recorder->transition($task, $actor, ApprovalAction::Return, PmStatus::Returned, 'One.');
        $task->update(['status' => PmStatus::ForValidation]);
        $second = $this->recorder->transition($task, $actor, ApprovalAction::Approve, PmStatus::Completed);

        $this->assertSame([$first->id, $second->id], $task->approvalRecords()->chronological()->pluck('id')->all());
        Carbon::setTestNow();
    }

    public function test_the_morph_map_uses_a_stable_alias(): void
    {
        $this->assertSame(PmTask::class, Relation::getMorphedModel('pm_task'));
        $this->assertSame('pm_task', (new PmTask)->getMorphClass());
    }

    public function test_the_action_enum_has_the_four_glossary_actions(): void
    {
        $this->assertSame(['approve', 'return', 'reject', 'cancel'], array_column(ApprovalAction::cases(), 'value'));
    }
}
