<?php

namespace App\Models;

use App\Enums\WorkOrderStatus;
use Illuminate\Auth\Access\AuthorizationException;
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
 * One completion declaration (revision) by the Work Order's Site Vendor (issue 20): the actual quantity used per Work
 * Order line, completion documents and photos, and notes. Revisions start at 1 and grow with each resubmission after
 * an EIC Return; the latest is the current one, earlier ones are kept unchanged. Not an Approval Record.
 */
#[Fillable(['revision', 'notes', 'submitted_by_user_id', 'submitted_at'])]
class WorkOrderCompletion extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'work_order_id' => 'integer',
            'revision' => 'integer',
            'submitted_by_user_id' => 'integer',
            'submitted_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<WorkOrder, $this>
     */
    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    /**
     * @return HasMany<WorkOrderCompletionLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(WorkOrderCompletionLine::class)->orderBy('id');
    }

    /**
     * @return HasMany<WorkOrderCompletionFile, $this>
     */
    public function files(): HasMany
    {
        return $this->hasMany(WorkOrderCompletionFile::class)->orderBy('id');
    }

    /**
     * Whether the user is staff of the Work Order's Site Vendor: the vendor captured on its E-Call at filing (not
     * re-routed if the Site Vendor changes later). Vendor membership, not an approver right.
     */
    public static function isSiteVendorStaff(User $user, WorkOrder $workOrder): bool
    {
        $vendorId = ECall::query()->whereKey($workOrder->e_call_id)->value('vendor_id');

        return $user->vendor_id !== null && $vendorId !== null && $user->vendor_id === (int) $vendorId;
    }

    /**
     * Declare completion: the next revision, and the Work Order becomes Implemented. The Work Order row is locked
     * while the Vendor and the For Implementation status are re-checked. Stored files are deleted if anything fails.
     *
     * @param  list<array{line: WorkOrderLine, quantity_thousandths: int}>  $lines  One per Work Order line.
     * @param  list<UploadedFile>  $documents
     * @param  list<UploadedFile>  $photos
     *
     * @throws AuthorizationException when the user is not the Site Vendor's staff.
     * @throws ValidationException when the Work Order is not For Implementation.
     */
    public static function submit(WorkOrder $workOrder, User $vendorStaff, ?string $notes, array $lines, array $documents, array $photos): self
    {
        $disk = config('pss.e_call_attachments.disk');
        $storedPaths = [];

        try {
            return DB::transaction(function () use ($workOrder, $vendorStaff, $notes, $lines, $documents, $photos, $disk, &$storedPaths): self {
                $locked = WorkOrder::query()->lockForUpdate()->findOrFail($workOrder->id);

                if (! static::isSiteVendorStaff($vendorStaff, $locked)) {
                    throw new AuthorizationException("Only the Site Vendor's staff can declare this Work Order complete.");
                }

                if ($locked->getRawOriginal('status') !== WorkOrderStatus::ForImplementation->value) {
                    throw static::notForImplementation();
                }

                $completion = $locked->completions()->create([
                    'revision' => (int) $locked->completions()->max('revision') + 1,
                    'notes' => $notes,
                    'submitted_by_user_id' => $vendorStaff->id,
                    'submitted_at' => now(),
                ]);

                $completion->lines()->createMany(array_map(fn (array $line): array => [
                    'work_order_line_id' => $line['line']->id,
                    'actual_quantity_thousandths' => $line['quantity_thousandths'],
                ], $lines));

                foreach ([WorkOrderCompletionFile::KIND_DOCUMENT => $documents, WorkOrderCompletionFile::KIND_PHOTO => $photos] as $kind => $files) {
                    foreach ($files as $file) {
                        $path = $file->store("work-orders/{$locked->id}/completions/{$completion->revision}", $disk);
                        $storedPaths[] = $path;

                        $completion->files()->create([
                            'kind' => $kind,
                            'disk' => $disk,
                            'path' => $path,
                            'original_name' => $file->getClientOriginalName(),
                            'mime_type' => $file->getMimeType(),
                            'size_bytes' => $file->getSize(),
                        ]);
                    }
                }

                $locked->update(['status' => WorkOrderStatus::Implemented]);

                return $completion;
            });
        } catch (Throwable $exception) {
            if ($storedPaths !== []) {
                Storage::disk($disk)->delete($storedPaths);
            }

            throw $exception;
        }
    }

    public static function notForImplementation(): ValidationException
    {
        return ValidationException::withMessages(['work_order' => 'Completion can only be declared while the Work Order is For Implementation.']);
    }
}
