<?php

namespace App\Models;

use App\Enums\PmStatus;
use Database\Factories\PmTaskFactory;
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
    'status', 'due_from_date', 'due_date', 'work_done_date',
])]
class PmTask extends Model
{
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
     * Move a Scheduled task to In Progress. The row is locked so two starts can't both succeed.
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

            $lockedTask->update(['status' => PmStatus::InProgress]);

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
