<?php

namespace App\Models;

use App\Approvals\ApprovalRecorder;
use App\Approvals\ApprovalSubject;
use App\Approvals\HasApprovalRecords;
use App\Enums\ApprovalAction;
use App\Enums\ECallPriority;
use App\Enums\ECallStatus;
use App\MasterData\Data\Equipment;
use App\MasterData\Data\Site;
use Database\Factories\ECallFactory;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * A request for corrective work at a Site, filed by a Requestor. It is routed to the Site Vendor and EIC
 * current at filing time, which are copied onto the record together with Site and Equipment Snapshots.
 */
#[Fillable([
    'site_id', 'site_name_snapshot',
    'equipment_id', 'equipment_name_snapshot', 'equipment_category_id', 'equipment_category_snapshot', 'equipment_location_snapshot',
    'nature_of_issue', 'description', 'priority', 'status',
    'requestor_user_id', 'requestor_employee_id', 'vendor_id', 'eic_employee_id', 'pm_task_id', 'approved_proposal_id',
])]
class ECall extends Model implements ApprovalSubject
{
    use HasApprovalRecords;

    /** @use HasFactory<ECallFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'priority' => ECallPriority::class,
            'status' => ECallStatus::class,
            'requestor_user_id' => 'integer',
            'vendor_id' => 'integer',
            'pm_task_id' => 'integer',
            'approved_proposal_id' => 'integer',
        ];
    }

    /**
     * The FSR + BOQ revision the EIC approved. Null until approved.
     *
     * @return BelongsTo<ECallProposal, $this>
     */
    public function approvedProposal(): BelongsTo
    {
        return $this->belongsTo(ECallProposal::class, 'approved_proposal_id');
    }

    /**
     * Whether the user may review E-Calls at the Site: the Site's current EIC, and never vendor staff (an EIC is an
     * employee of the organisation). With $lockForUpdate the Site's settings row is locked, so an EIC replacement
     * can't slip in before the caller's transaction commits.
     */
    public static function isReviewer(string $siteId, User $user, bool $lockForUpdate = false): bool
    {
        return $user->vendor_id === null && SiteSetting::isCurrentEic($siteId, $user, $lockForUpdate);
    }

    /**
     * The Site's current EIC approves the latest FSR + BOQ revision: Approved, and that revision is recorded as the
     * approved one. Remarks are optional.
     *
     * @throws AuthorizationException when the actor is no longer the Site's current EIC.
     * @throws ValidationException when the E-Call is not For EIC Review.
     */
    public static function approveProposal(ECall $eCall, User $eic, ?string $remarks): self
    {
        return static::reviewProposal($eCall, $eic, ApprovalAction::Approve, ECallStatus::Approved, $remarks, function (self $lockedECall): void {
            $latestProposalId = $lockedECall->proposals()->orderByDesc('revision')->value('id');

            if ($latestProposalId === null) {
                throw ValidationException::withMessages(['e_call' => 'This E-Call has no FSR and BOQ to approve.']);
            }

            $lockedECall->approved_proposal_id = $latestProposalId;
        });
    }

    /**
     * The Site's current EIC returns the FSR + BOQ to the vendor with remarks: Returned. The vendor resubmits.
     *
     * @throws AuthorizationException when the actor is no longer the Site's current EIC.
     * @throws ValidationException when the E-Call is not For EIC Review.
     */
    public static function returnProposal(ECall $eCall, User $eic, string $remarks): self
    {
        return static::reviewProposal($eCall, $eic, ApprovalAction::Return, ECallStatus::Returned, $remarks);
    }

    /**
     * The Site's current EIC rejects the E-Call with remarks: Rejected, which is final.
     *
     * @throws AuthorizationException when the actor is no longer the Site's current EIC.
     * @throws ValidationException when the E-Call is not For EIC Review.
     */
    public static function rejectProposal(ECall $eCall, User $eic, string $remarks): self
    {
        return static::reviewProposal($eCall, $eic, ApprovalAction::Reject, ECallStatus::Rejected, $remarks);
    }

    /**
     * Lock the E-Call and the Site's settings row, re-check that the actor is still the Site's current EIC and that
     * the E-Call is still For EIC Review, apply any extra change, then record the decision. The locks are held until
     * the new status, the extra change and the Approval Record are all written.
     *
     * @param  (callable(self): void)|null  $beforeTransition  Extra changes saved together with the new status.
     */
    private static function reviewProposal(ECall $eCall, User $eic, ApprovalAction $action, ECallStatus $toStatus, ?string $remarks, ?callable $beforeTransition = null): self
    {
        return DB::transaction(function () use ($eCall, $eic, $action, $toStatus, $remarks, $beforeTransition): self {
            $lockedECall = static::query()->lockForUpdate()->findOrFail($eCall->id);

            if (! static::isReviewer($lockedECall->site_id, $eic, lockForUpdate: true)) {
                throw new AuthorizationException("Only the Site's current EIC can review this E-Call.");
            }

            if ($lockedECall->status !== ECallStatus::ForEicReview) {
                throw ValidationException::withMessages(['e_call' => 'Only an E-Call that is For EIC Review can be approved, returned or rejected.']);
            }

            if ($beforeTransition !== null) {
                $beforeTransition($lockedECall);
                $lockedECall->save();
            }

            app(ApprovalRecorder::class)->transition($lockedECall, $eic, $action, $toStatus, $remarks);

            return $lockedECall;
        });
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requestor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requestor_user_id');
    }

    /**
     * The Site Vendor captured at filing time.
     *
     * @return BelongsTo<Vendor, $this>
     */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /**
     * @return HasMany<ECallAttachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(ECallAttachment::class)->orderBy('id');
    }

    /**
     * The Site Vendor's FSR + BOQ revisions.
     *
     * @return HasMany<ECallProposal, $this>
     */
    public function proposals(): HasMany
    {
        return $this->hasMany(ECallProposal::class);
    }

    /**
     * Vendor staff only see E-Calls routed to their Vendor. Everyone else is not scoped yet (issue 04).
     *
     * @param  Builder<ECall>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        $query->when($user->vendor_id !== null, fn (Builder $query) => $query->where('vendor_id', $user->vendor_id));
    }

    /**
     * File a new E-Call in For Vendor Assessment, routed to the Site's current Site Vendor and EIC, and store its attachments.
     *
     * @param  array{nature_of_issue: string, description: string, priority: string, pm_task_id: int|null}  $details
     * @param  list<UploadedFile>  $files
     */
    public static function file(
        User $requestor,
        Site $site,
        ?Equipment $equipment,
        ?string $equipmentCategoryName,
        SiteSetting $routing,
        array $details,
        array $files,
    ): self {
        $disk = config('pss.e_call_attachments.disk');
        $storedPaths = [];

        try {
            return DB::transaction(function () use ($requestor, $site, $equipment, $equipmentCategoryName, $routing, $details, $files, $disk, &$storedPaths): self {
                $eCall = static::create([
                    ...$details,
                    'site_id' => $site->id,
                    'site_name_snapshot' => $site->name,
                    'equipment_id' => $equipment?->id,
                    'equipment_name_snapshot' => $equipment?->name,
                    'equipment_category_id' => $equipment?->categoryId,
                    'equipment_category_snapshot' => $equipmentCategoryName,
                    'equipment_location_snapshot' => $equipment?->location,
                    'status' => ECallStatus::ForVendorAssessment,
                    'requestor_user_id' => $requestor->id,
                    'requestor_employee_id' => $requestor->employee_id,
                    'vendor_id' => $routing->vendor_id,
                    'eic_employee_id' => $routing->eic_employee_id,
                ]);

                foreach ($files as $file) {
                    $path = $file->store("e-calls/{$eCall->id}", $disk);
                    $storedPaths[] = $path;

                    $eCall->attachments()->create([
                        'disk' => $disk,
                        'path' => $path,
                        'original_name' => $file->getClientOriginalName(),
                        'mime_type' => $file->getMimeType(),
                        'size_bytes' => $file->getSize(),
                    ]);
                }

                return $eCall;
            });
        } catch (Throwable $exception) {
            Storage::disk($disk)->delete(array_filter($storedPaths));

            throw $exception;
        }
    }
}
