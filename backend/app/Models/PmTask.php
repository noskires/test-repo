<?php

namespace App\Models;

use App\Approvals\ApprovalRecorder;
use App\Approvals\ApprovalSubject;
use App\Approvals\HasApprovalRecords;
use App\Enums\ApprovalAction;
use App\Enums\PmStatus;
use App\Events\PmTaskCompleted;
use Database\Factories\PmTaskFactory;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * One scheduled check-up of one external Equipment, assigned to the Site Vendor and EIC at generation time,
 * with Site and Equipment Snapshots and the checklist version it was generated with. Historical tasks are kept.
 * At most one task per Equipment is open; the unique open_equipment_id column enforces it in the database.
 */
#[Fillable([
    'site_id', 'site_name_snapshot',
    'equipment_id', 'equipment_name_snapshot', 'equipment_category_id', 'equipment_category_snapshot', 'equipment_location_snapshot',
    'vendor_id', 'eic_employee_id', 'checklist_template_version_id', 'no_checklist', 'equipment_removed',
    'status', 'due_from_date', 'due_date', 'work_done_date', 'started_at',
])]
class PmTask extends Model implements ApprovalSubject
{
    use HasApprovalRecords;

    /** @use HasFactory<PmTaskFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'no_checklist' => false,
        'equipment_removed' => false,
    ];

    /**
     * Keep the open key in step with the status, so the unique index only covers open tasks.
     */
    protected static function booted(): void
    {
        static::saving(function (PmTask $task): void {
            $task->open_equipment_id = $task->status->isOpen() ? $task->equipment_id : null;
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'vendor_id' => 'integer',
            'checklist_template_version_id' => 'integer',
            'no_checklist' => 'boolean',
            'equipment_removed' => 'boolean',
            'status' => PmStatus::class,
            'due_from_date' => 'immutable_date',
            'due_date' => 'immutable_date',
            'work_done_date' => 'immutable_date',
            'started_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Vendor, $this>
     */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /**
     * @return BelongsTo<ChecklistTemplateVersion, $this>
     */
    public function checklistVersion(): BelongsTo
    {
        return $this->belongsTo(ChecklistTemplateVersion::class, 'checklist_template_version_id');
    }

    /**
     * The Site Vendor's PM Report revisions.
     *
     * @return HasMany<PmReport, $this>
     */
    public function reports(): HasMany
    {
        return $this->hasMany(PmReport::class);
    }

    /**
     * The E-Calls the EIC filed from this task's findings (issue 13).
     *
     * @return HasMany<ECall, $this>
     */
    public function eCalls(): HasMany
    {
        return $this->hasMany(ECall::class);
    }

    /**
     * Move a Scheduled task to In Progress and record when it was started. The row is locked so two starts
     * can't both succeed.
     *
     * @throws ValidationException when the task is not Scheduled.
     */
    public static function start(PmTask $task): self
    {
        return DB::transaction(function () use ($task): self {
            $lockedTask = static::query()->lockForUpdate()->findOrFail($task->id);

            if ($lockedTask->status !== PmStatus::Scheduled) {
                throw ValidationException::withMessages(['pm_task' => 'Only a Scheduled PM Task can be started.']);
            }

            $lockedTask->update(['status' => PmStatus::InProgress, 'started_at' => now()]);

            return $lockedTask;
        });
    }

    /**
     * The EIC approves the PM Report: Completed, keeping the vendor's Work Done Date as reported. Writes an
     * Approval Record and announces PmTaskCompleted after commit. Releasing the open-task key happens in the
     * saving hook.
     *
     * @throws AuthorizationException when the actor is no longer the Site's current EIC.
     * @throws ValidationException when the task is not For Validation.
     */
    public static function approveReport(PmTask $task, User $eic, ?string $remarks): self
    {
        $task = static::validateReport($task, $eic, ApprovalAction::Approve, PmStatus::Completed, $remarks);

        PmTaskCompleted::dispatch($task->id, $task->equipment_id, $task->work_done_date);

        return $task;
    }

    /**
     * The EIC returns the PM Report to the vendor with remarks: Returned. Writes an Approval Record.
     *
     * @throws AuthorizationException when the actor is no longer the Site's current EIC.
     * @throws ValidationException when the task is not For Validation.
     */
    public static function returnReport(PmTask $task, User $eic, string $remarks): self
    {
        return static::validateReport($task, $eic, ApprovalAction::Return, PmStatus::Returned, $remarks);
    }

    /**
     * Lock the task and the Site's settings row, re-check that the actor is still the Site's current EIC (an EIC
     * replaced after the policy check passed is refused), check the task is For Validation, and record the
     * decision. The locks are held until the recorder has written the new status and its record.
     *
     * @throws AuthorizationException when the actor is no longer the Site's current EIC.
     */
    private static function validateReport(PmTask $task, User $eic, ApprovalAction $action, PmStatus $toStatus, ?string $remarks): self
    {
        return DB::transaction(function () use ($task, $eic, $action, $toStatus, $remarks): self {
            $lockedTask = static::query()->lockForUpdate()->findOrFail($task->id);

            if (! SiteSetting::isActingEic($lockedTask->site_id, $eic, lockForUpdate: true)) {
                throw new AuthorizationException("Only the Site's current EIC can approve or return this PM Task.");
            }

            if ($lockedTask->status !== PmStatus::ForValidation) {
                throw ValidationException::withMessages(['pm_task' => 'Only a PM Task that is For Validation can be approved or returned.']);
            }

            app(ApprovalRecorder::class)->transition($lockedTask, $eic, $action, $toStatus, $remarks);

            return $lockedTask;
        });
    }

    /**
     * Scope the query to tasks that are not Completed or Cancelled.
     *
     * @param  Builder<PmTask>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNotNull('open_equipment_id');
    }
}
