<?php

namespace App\Models;

use App\Enums\PmStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * One revision of the Site Vendor's PM Report for a PM Task: checklist responses against the task's checklist
 * version, the Work Done Date, findings, materials used and attachments. Revisions start at 1 and grow with each
 * resubmission after Returned. The latest is the current one; earlier ones are kept unchanged.
 */
#[Fillable(['revision', 'checklist_template_version_id', 'work_done_date', 'findings', 'submitted_by_user_id', 'submitted_at'])]
class PmReport extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'pm_task_id' => 'integer',
            'revision' => 'integer',
            'checklist_template_version_id' => 'integer',
            'work_done_date' => 'immutable_date',
            'submitted_by_user_id' => 'integer',
            'submitted_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<PmTask, $this>
     */
    public function pmTask(): BelongsTo
    {
        return $this->belongsTo(PmTask::class);
    }

    /**
     * @return HasMany<PmReportResponse, $this>
     */
    public function responses(): HasMany
    {
        return $this->hasMany(PmReportResponse::class)->orderBy('id');
    }

    /**
     * @return HasMany<PmReportMaterial, $this>
     */
    public function materials(): HasMany
    {
        return $this->hasMany(PmReportMaterial::class)->orderBy('id');
    }

    /**
     * @return HasMany<PmReportAttachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(PmReportAttachment::class)->orderBy('id');
    }

    /**
     * Submit the next report revision, write the Work Done Date onto the task and move it to For Validation.
     * The task row is locked so the status check and the revision number can't race. Stored files are deleted
     * again if anything fails.
     *
     * @param  list<array{item: ChecklistItem, value: ?string, remarks: ?string, is_failed: bool, photos: list<UploadedFile>}>  $responses
     * @param  list<array{description: string, quantity_thousandths: int}>  $materials
     * @param  list<UploadedFile>  $attachments
     *
     * @throws ValidationException when the task no longer accepts a report.
     */
    public static function submit(
        PmTask $task,
        User $submitter,
        string $workDoneDate,
        ?string $findings,
        array $responses,
        array $materials,
        array $attachments,
    ): self {
        $disk = config('pss.e_call_attachments.disk');
        $storedPaths = [];

        try {
            return DB::transaction(function () use ($task, $submitter, $workDoneDate, $findings, $responses, $materials, $attachments, $disk, &$storedPaths): self {
                $lockedTask = PmTask::query()->lockForUpdate()->findOrFail($task->id);

                if (! in_array($lockedTask->status, [PmStatus::InProgress, PmStatus::Returned], true)) {
                    throw ValidationException::withMessages([
                        'pm_task' => 'A PM Report can only be submitted while the PM Task is In Progress or Returned.',
                    ]);
                }

                $report = $lockedTask->reports()->create([
                    'revision' => (int) $lockedTask->reports()->max('revision') + 1,
                    'checklist_template_version_id' => $lockedTask->checklist_template_version_id,
                    'work_done_date' => $workDoneDate,
                    'findings' => $findings,
                    'submitted_by_user_id' => $submitter->id,
                    'submitted_at' => now(),
                ]);

                $storeFile = function (UploadedFile $file, ?int $checklistItemId) use ($report, $disk, &$storedPaths): void {
                    $path = $file->store("pm-tasks/{$report->pm_task_id}/reports/{$report->revision}", $disk);
                    $storedPaths[] = $path;

                    $report->attachments()->create([
                        'checklist_item_id' => $checklistItemId,
                        'disk' => $disk,
                        'path' => $path,
                        'original_name' => $file->getClientOriginalName(),
                        'mime_type' => $file->getMimeType(),
                        'size_bytes' => $file->getSize(),
                    ]);
                };

                foreach ($responses as $response) {
                    $report->responses()->create([
                        'checklist_item_id' => $response['item']->id,
                        'value' => $response['value'],
                        'remarks' => $response['remarks'],
                        'is_failed' => $response['is_failed'],
                    ]);

                    foreach ($response['photos'] as $photo) {
                        $storeFile($photo, $response['item']->id);
                    }
                }

                $report->materials()->createMany($materials);

                foreach ($attachments as $attachment) {
                    $storeFile($attachment, null);
                }

                $lockedTask->update(['status' => PmStatus::ForValidation, 'work_done_date' => $workDoneDate]);

                return $report;
            });
        } catch (Throwable $exception) {
            Storage::disk($disk)->delete($storedPaths);

            throw $exception;
        }
    }
}
