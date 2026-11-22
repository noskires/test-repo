<?php

namespace App\Models;

use App\Approvals\ApprovalRecorder;
use App\Approvals\ApprovalSubject;
use App\Approvals\HasApprovalRecords;
use App\Billing\BoqQuantity;
use App\Enums\ApprovalAction;
use App\Enums\ECallStatus;
use App\Enums\WorkOrderStatus;
use App\MasterData\Data\Employee;
use App\MasterData\ImmediateHeadChain;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use OverflowException;
use Throwable;

/**
 * The approved, priced job created by the Requestor from exactly one Approved E-Call (GLOSSARY "Work Order").
 * Built from the E-Call's approved FSR + BOQ revision; creating it closes the E-Call. It starts Open (a draft its
 * creator can edit). Money per ADR 0002: exact line totals, estimated amount rounded once, half-up, to the centavo.
 */
#[Fillable([
    'e_call_id', 'e_call_proposal_id', 'status', 'created_by_user_id',
    'estimated_amount_millicentavos', 'estimated_amount_centavos',
])]
class WorkOrder extends Model implements ApprovalSubject
{
    use HasApprovalRecords;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'e_call_id' => 'integer',
            'e_call_proposal_id' => 'integer',
            'status' => WorkOrderStatus::class,
            'created_by_user_id' => 'integer',
            'estimated_amount_millicentavos' => 'integer',
            'estimated_amount_centavos' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<ECall, $this>
     */
    public function eCall(): BelongsTo
    {
        return $this->belongsTo(ECall::class);
    }

    /**
     * The approved FSR + BOQ revision this Work Order was built from.
     *
     * @return BelongsTo<ECallProposal, $this>
     */
    public function proposal(): BelongsTo
    {
        return $this->belongsTo(ECallProposal::class, 'e_call_proposal_id');
    }

    /**
     * @return HasMany<WorkOrderLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(WorkOrderLine::class)->orderBy('id');
    }

    /**
     * @return HasMany<WorkOrderAttachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(WorkOrderAttachment::class)->orderBy('id');
    }

    /**
     * Create the E-Call's one Work Order and close the E-Call. The E-Call row is locked while the requestor, the
     * Approved status and "no Work Order yet" are re-checked; the unique e_call_id index catches a concurrent create.
     *
     * @param  list<array{line: ECallProposalLine, quantity_thousandths: int}>  $lines
     * @param  list<UploadedFile>  $attachments
     *
     * @throws AuthorizationException when the user is not the E-Call's Requestor.
     * @throws ValidationException when the E-Call is not Approved, already has a Work Order, or a line is invalid.
     */
    public static function createFromECall(ECall $eCall, User $requestor, array $lines, array $attachments): self
    {
        $storedPaths = [];

        try {
            return DB::transaction(function () use ($eCall, $requestor, $lines, $attachments, &$storedPaths): self {
                $lockedECall = ECall::query()->lockForUpdate()->findOrFail($eCall->id);

                if ($lockedECall->requestor_user_id !== $requestor->id) {
                    throw new AuthorizationException("Only the E-Call's Requestor can create its Work Order.");
                }

                if ($lockedECall->status !== ECallStatus::Approved || $lockedECall->approved_proposal_id === null) {
                    throw ValidationException::withMessages(['e_call' => 'A Work Order can only be created from an Approved E-Call.']);
                }

                if (static::query()->where('e_call_id', $lockedECall->id)->exists()) {
                    throw static::alreadyHasAWorkOrder();
                }

                $workOrder = static::create([
                    'e_call_id' => $lockedECall->id,
                    'e_call_proposal_id' => $lockedECall->approved_proposal_id,
                    'status' => WorkOrderStatus::Open,
                    'created_by_user_id' => $requestor->id,
                    'estimated_amount_millicentavos' => 0,
                    'estimated_amount_centavos' => 0,
                ]);

                $workOrder->replaceLines($lines);
                $workOrder->storeAttachments($attachments, $storedPaths);

                $lockedECall->update(['status' => ECallStatus::Closed]);

                return $workOrder;
            });
        } catch (UniqueConstraintViolationException $exception) {
            static::deleteFiles($storedPaths);

            if (str_contains($exception->getMessage(), 'e_call_id')) {
                throw static::alreadyHasAWorkOrder();
            }

            throw $exception;
        } catch (Throwable $exception) {
            static::deleteFiles($storedPaths);

            throw $exception;
        }
    }

    /**
     * Replace the lines (and add attachments) of an Open Work Order. Only its creator may do it. The row is locked
     * while the creator and the Open status are re-checked. The status is compared as the stored value, so the
     * guard also holds for the statuses later issues add.
     *
     * @param  list<array{line: ECallProposalLine, quantity_thousandths: int}>  $lines
     * @param  list<UploadedFile>  $attachments
     *
     * @throws AuthorizationException when the user is not the creator.
     * @throws ValidationException when the Work Order is no longer Open, or a line is invalid.
     */
    public static function revise(WorkOrder $workOrder, User $creator, array $lines, array $attachments): self
    {
        $storedPaths = [];

        try {
            return DB::transaction(function () use ($workOrder, $creator, $lines, $attachments, &$storedPaths): self {
                $locked = static::query()->lockForUpdate()->findOrFail($workOrder->id);

                if ($locked->created_by_user_id !== $creator->id) {
                    throw new AuthorizationException('Only the creator can edit this Work Order.');
                }

                if ($locked->getRawOriginal('status') !== WorkOrderStatus::Open->value) {
                    throw ValidationException::withMessages(['work_order' => 'A Work Order can only be edited while it is Open.']);
                }

                $locked->lines()->delete();
                $locked->replaceLines($lines);
                $locked->storeAttachments($attachments, $storedPaths);

                return $locked;
            });
        } catch (Throwable $exception) {
            static::deleteFiles($storedPaths);

            throw $exception;
        }
    }

    /**
     * The creator submits an Open Work Order for endorsement: For LVL1 Endorsement. Both endorsement levels must
     * resolve from the E-Call Requestor's Immediate Head chain right now, or the submit is blocked (422 naming the
     * level) and the Work Order stays Open. Submitting writes no Approval Record (it isn't an approval action).
     *
     * @throws AuthorizationException when the user is not the creator.
     * @throws ValidationException when the Work Order is not Open or the chain is short or missing.
     */
    public static function submit(WorkOrder $workOrder, User $creator): self
    {
        return DB::transaction(function () use ($workOrder, $creator): self {
            $locked = static::query()->lockForUpdate()->findOrFail($workOrder->id);

            if ($locked->created_by_user_id !== $creator->id) {
                throw new AuthorizationException('Only the creator can submit this Work Order.');
            }

            if ($locked->getRawOriginal('status') !== WorkOrderStatus::Open->value) {
                throw ValidationException::withMessages(['work_order' => 'Only an Open Work Order can be submitted for endorsement.']);
            }

            $locked->endorserAtLevel(1);
            $locked->endorserAtLevel(2);

            $locked->update(['status' => WorkOrderStatus::ForLvl1Endorsement]);

            return $locked;
        });
    }

    /**
     * The current level's endorser approves, returns or rejects. The endorser is resolved from the E-Call
     * Requestor's Immediate Head chain at the moment of the action, inside the lock: an org-chart change re-routes
     * a waiting Work Order, and a chain that no longer resolves blocks every action (422) until it is fixed.
     *
     * @throws AuthorizationException when the user is not the current level's endorser.
     * @throws ValidationException when no endorsement is pending or the current endorser cannot be resolved.
     */
    public static function endorse(WorkOrder $workOrder, User $endorser, ApprovalAction $action, ?string $remarks): self
    {
        return DB::transaction(function () use ($workOrder, $endorser, $action, $remarks): self {
            $locked = static::query()->lockForUpdate()->findOrFail($workOrder->id);
            $level = $locked->pendingEndorsementLevel();

            if ($level === null) {
                throw ValidationException::withMessages(['work_order' => 'This Work Order is not waiting for an endorsement.']);
            }

            if (! static::isEndorser($endorser, $locked->endorserAtLevel($level))) {
                throw new AuthorizationException("Only the Requestor's current level {$level} endorser can act on this Work Order.");
            }

            app(ApprovalRecorder::class)->transition($locked, $endorser, $action, $locked->status->afterEndorsement($action), $remarks);

            return $locked;
        });
    }

    /**
     * Whether the user is the current endorser for this Work Order's pending level. Used by the policy for the early
     * 403; true when no endorsement is pending or the endorser can't be resolved, so WorkOrder::endorse reports
     * those as 422.
     */
    public function mayBeEndorsedBy(User $user): bool
    {
        $level = $this->pendingEndorsementLevel();

        if ($level === null) {
            return true;
        }

        $head = $this->resolveEndorser($level);

        return $head === null || static::isEndorser($user, $head);
    }

    /**
     * The endorser is the resolved head by employee ID, and never vendor staff (mirrors SiteSetting::isActingEic).
     */
    private static function isEndorser(User $user, Employee $head): bool
    {
        return $user->vendor_id === null && $user->employee_id !== null && $user->employee_id === $head->id;
    }

    private function pendingEndorsementLevel(): ?int
    {
        return WorkOrderStatus::tryFrom((string) $this->getRawOriginal('status'))?->endorsementLevel();
    }

    /**
     * The E-Call Requestor's Immediate Head at the given level, resolved now. Throws a 422 naming the level when the
     * Requestor has no employee ID or the chain stops short.
     */
    private function endorserAtLevel(int $level): Employee
    {
        if ($this->requestorEmployeeId() === null) {
            throw ValidationException::withMessages(['work_order' => "The Requestor has no employee ID, so the level {$level} endorser cannot be found."]);
        }

        return $this->resolveEndorser($level)
            ?? throw ValidationException::withMessages(['work_order' => "No level {$level} endorser: the Requestor's Immediate Head chain stops before level {$level}."]);
    }

    private function resolveEndorser(int $level): ?Employee
    {
        $requestorEmployeeId = $this->requestorEmployeeId();

        return $requestorEmployeeId === null ? null : app(ImmediateHeadChain::class)->headAtLevel($requestorEmployeeId, $level);
    }

    private function requestorEmployeeId(): ?string
    {
        return ECall::query()->whereKey($this->e_call_id)->value('requestor_employee_id');
    }

    /**
     * Write the lines (copying description, unit and unit price from the BOQ line) and the estimated amount: the
     * exact sum of the exact line totals, and that sum rounded half-up to the centavo.
     *
     * @param  list<array{line: ECallProposalLine, quantity_thousandths: int}>  $lines
     */
    private function replaceLines(array $lines): void
    {
        $lineAttributes = [];

        foreach ($lines as ['line' => $boqLine, 'quantity_thousandths' => $quantityThousandths]) {
            if ($boqLine->e_call_proposal_id !== $this->e_call_proposal_id) {
                throw ValidationException::withMessages(['lines' => 'Work Order lines must come from the approved BOQ revision.']);
            }

            $lineAttributes[] = [
                'e_call_proposal_line_id' => $boqLine->id,
                'code' => $boqLine->code,
                'description' => $boqLine->description,
                'type' => $boqLine->type,
                'unit_of_measure' => $boqLine->unit_of_measure,
                'quantity_thousandths' => $quantityThousandths,
                'unit_price_centavos' => $boqLine->unit_price_centavos,
                'line_total_millicentavos' => $this->exactly(fn (): int => BoqQuantity::lineTotalMillicentavos($quantityThousandths, $boqLine->unit_price_centavos)),
            ];
        }

        $this->lines()->createMany($lineAttributes);

        $exactTotal = $this->exactly(fn (): int => BoqQuantity::sumMillicentavos(array_column($lineAttributes, 'line_total_millicentavos')));

        $this->update([
            'estimated_amount_millicentavos' => $exactTotal,
            'estimated_amount_centavos' => BoqQuantity::roundHalfUpToCentavos($exactTotal),
        ]);
    }

    /**
     * @param  list<UploadedFile>  $attachments
     * @param  list<string>  $storedPaths  Filled with every stored path, so a failed transaction can delete them.
     */
    private function storeAttachments(array $attachments, array &$storedPaths): void
    {
        $disk = config('pss.e_call_attachments.disk');

        foreach ($attachments as $file) {
            $path = $file->store("work-orders/{$this->id}", $disk);
            $storedPaths[] = $path;

            $this->attachments()->create([
                'disk' => $disk,
                'path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'size_bytes' => $file->getSize(),
            ]);
        }
    }

    /**
     * @param  callable(): int  $computation
     */
    private function exactly(callable $computation): int
    {
        try {
            return $computation();
        } catch (OverflowException) {
            throw ValidationException::withMessages(['lines' => 'The Work Order total is too large to store exactly.']);
        }
    }

    /**
     * @param  list<string>  $paths
     */
    private static function deleteFiles(array $paths): void
    {
        if ($paths !== []) {
            Storage::disk(config('pss.e_call_attachments.disk'))->delete($paths);
        }
    }

    private static function alreadyHasAWorkOrder(): ValidationException
    {
        return ValidationException::withMessages(['e_call' => 'This E-Call already has a Work Order.']);
    }
}
