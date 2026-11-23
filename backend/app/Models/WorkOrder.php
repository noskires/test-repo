<?php

namespace App\Models;

use App\Approvals\ApprovalRecorder;
use App\Approvals\ApprovalSubject;
use App\Approvals\HasApprovalRecords;
use App\Billing\BillingLevels;
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
use Illuminate\Database\Eloquent\Relations\HasOne;
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
    'estimated_amount_millicentavos', 'estimated_amount_centavos', 'submitted_at', 'endorsement_round_after_record_id',
    'billing_amount_millicentavos', 'billing_amount_centavos',
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
            'submitted_at' => 'immutable_datetime',
            'endorsement_round_after_record_id' => 'integer',
            'billing_amount_millicentavos' => 'integer',
            'billing_amount_centavos' => 'integer',
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
     * The Site Vendor's completion declarations (issue 20), one per revision.
     *
     * @return HasMany<WorkOrderCompletion, $this>
     */
    public function completions(): HasMany
    {
        return $this->hasMany(WorkOrderCompletion::class);
    }

    /**
     * The latest completion revision: its actual quantities are the ones the EIC reviews (issue 21).
     *
     * @return HasOne<WorkOrderCompletion, $this>
     */
    public function latestCompletion(): HasOne
    {
        return $this->hasOne(WorkOrderCompletion::class)->ofMany('revision', 'max');
    }

    /**
     * The current Site EIC validates the completed work: stores a validated quantity per line and the Billing Amount
     * (exact sum of validated quantity x unit price, rounded once half-up to centavos, ADR 0002), and moves the Work
     * Order into billing endorsement. Inside the lock: the acting EIC (403), the Implemented status (422), then the
     * billing chain for the levels the amount needs right now (separation of duties, then level 1 resolves; 422).
     * Nothing is stored unless every check passes.
     *
     * @param  list<array{line: WorkOrderLine, quantity_thousandths: int}>  $lines  One per Work Order line.
     *
     * @throws AuthorizationException when the user is no longer the current Site EIC.
     * @throws ValidationException when not Implemented, the amount overflows, or billing can't start.
     */
    public static function validateCompletion(WorkOrder $workOrder, User $eic, array $lines, ?string $remarks): self
    {
        return static::reviewCompletion($workOrder, $eic, function (self $locked) use ($eic, $lines, $remarks): void {
            $lineTotals = [];

            foreach ($lines as ['line' => $line, 'quantity_thousandths' => $quantity]) {
                if ($line->work_order_id !== $locked->id) {
                    throw ValidationException::withMessages(['lines' => 'Every validated line must belong to this Work Order.']);
                }

                $lineTotals[$line->id] = [$quantity, $locked->exactly(fn (): int => BoqQuantity::lineTotalMillicentavos($quantity, $line->unit_price_centavos))];
            }

            $exactTotal = $locked->exactly(fn (): int => BoqQuantity::sumMillicentavos(array_column($lineTotals, 1)));
            $billingAmountCentavos = BoqQuantity::roundHalfUpToCentavos($exactTotal);

            $locked->assertCanEnterBilling($billingAmountCentavos);

            foreach ($lineTotals as $lineId => [$quantity, $total]) {
                $locked->lines()->whereKey($lineId)->update([
                    'validated_quantity_thousandths' => $quantity,
                    'validated_line_total_millicentavos' => $total,
                ]);
            }

            $locked->update(['billing_amount_millicentavos' => $exactTotal, 'billing_amount_centavos' => $billingAmountCentavos]);

            app(ApprovalRecorder::class)->transition($locked, $eic, ApprovalAction::Approve, WorkOrderStatus::ForBillingEndorsementLvl1, $remarks);
        });
    }

    /**
     * The current Site EIC returns the completed work for rework: For Implementation (the vendor resubmits).
     *
     * @throws AuthorizationException when the user is no longer the current Site EIC.
     * @throws ValidationException when not Implemented.
     */
    public static function returnCompletion(WorkOrder $workOrder, User $eic, string $remarks): self
    {
        return static::reviewCompletion($workOrder, $eic, fn (self $locked) => app(ApprovalRecorder::class)
            ->transition($locked, $eic, ApprovalAction::Return, WorkOrderStatus::ForImplementation, $remarks));
    }

    /**
     * The current Site EIC rejects the completed work: Rejected, final.
     *
     * @throws AuthorizationException when the user is no longer the current Site EIC.
     * @throws ValidationException when not Implemented.
     */
    public static function rejectCompletion(WorkOrder $workOrder, User $eic, string $remarks): self
    {
        return static::reviewCompletion($workOrder, $eic, fn (self $locked) => app(ApprovalRecorder::class)
            ->transition($locked, $eic, ApprovalAction::Reject, WorkOrderStatus::Rejected, $remarks));
    }

    /**
     * Whether the user may act as the Work Order's EIC: the current Site EIC of its E-Call's Site (issue 37 rule).
     */
    public function isActingEic(User $user, bool $lockForUpdate = false): bool
    {
        $siteId = ECall::query()->whereKey($this->e_call_id)->value('site_id');

        return $siteId !== null && SiteSetting::isActingEic($siteId, $user, $lockForUpdate);
    }

    /**
     * Lock the Work Order, re-check the acting EIC and the Implemented status, then apply the decision.
     *
     * @param  callable(self): void  $decide
     */
    private static function reviewCompletion(WorkOrder $workOrder, User $eic, callable $decide): self
    {
        return DB::transaction(function () use ($workOrder, $eic, $decide): self {
            $locked = static::query()->lockForUpdate()->findOrFail($workOrder->id);

            if (! $locked->isActingEic($eic, lockForUpdate: true)) {
                throw new AuthorizationException("Only the Site's current EIC can validate this Work Order's completion.");
            }

            if ($locked->getRawOriginal('status') !== WorkOrderStatus::Implemented->value) {
                throw static::notImplemented();
            }

            $decide($locked);

            return $locked;
        });
    }

    public static function notImplemented(): ValidationException
    {
        return ValidationException::withMessages(['work_order' => 'Completed work can only be validated, returned or rejected while the Work Order is Implemented.']);
    }

    /**
     * Entering billing endorsement (issues 21, 23, 38): for the billing levels the amount needs under the CURRENT
     * thresholds, the Requestor's heads must not repeat and none may be the Requestor (separation of duties), and the
     * level 1 head must resolve now. Levels above 1 that don't resolve yet are checked by issue 23 when reached.
     *
     * @throws ValidationException naming the problem.
     */
    private function assertCanEnterBilling(int $billingAmountCentavos): void
    {
        $this->assertBillingChainSound(BillingLevels::requiredBillingLevels($billingAmountCentavos, BillingThresholdSetting::current()));
        $this->billingEndorserAtLevel(1);
    }

    /**
     * Billing separation of duties (issues 21, 23, 38): among the Requestor's heads at billing levels 1..$levels that
     * resolve right now, none may repeat and none may be the Requestor. A loop in the chain counts as a repeat.
     * Levels that don't resolve are not a conflict (they block only when the Work Order would wait there).
     *
     * @throws ValidationException naming the conflict, or when the Requestor has no employee ID.
     */
    private function assertBillingChainSound(int $levels): void
    {
        $requestorEmployeeId = $this->requestorEmployeeId();

        if ($requestorEmployeeId === null) {
            throw static::requestorHasNoEmployeeId(1);
        }

        $chain = app(ImmediateHeadChain::class);
        $seen = [];

        for ($level = 1; $level <= $levels; $level++) {
            $repeated = $chain->repeatedEmployeeAt($requestorEmployeeId, $level);
            $head = $repeated === null ? $chain->headAtLevel($requestorEmployeeId, $level) : null;
            $employeeId = $repeated ?? $head?->id;

            if ($employeeId === $requestorEmployeeId) {
                throw ValidationException::withMessages(['work_order' => "The level {$level} billing endorser would be the Requestor ({$employeeId}). Separation of duties requires someone else."]);
            }

            if ($employeeId !== null && isset($seen[$employeeId])) {
                throw ValidationException::withMessages(['work_order' => "Two billing endorsement levels resolve to the same person ({$employeeId}). Separation of duties requires different endorsers."]);
            }

            if ($employeeId !== null) {
                $seen[$employeeId] = true;
            }
        }
    }

    /**
     * The Requestor's head at the billing level, resolved now. Throws a 422 naming the level when the Requestor has
     * no employee ID or the chain stops short (block and flag, issue 23).
     */
    private function billingEndorserAtLevel(int $level): Employee
    {
        $requestorEmployeeId = $this->requestorEmployeeId();

        if ($requestorEmployeeId === null) {
            throw static::requestorHasNoEmployeeId($level);
        }

        return app(ImmediateHeadChain::class)->headAtLevel($requestorEmployeeId, $level)
            ?? throw ValidationException::withMessages(['work_order' => "No level {$level} billing endorser: the Requestor's Immediate Head chain stops before level {$level}."]);
    }

    private static function requestorHasNoEmployeeId(int $level): ValidationException
    {
        return ValidationException::withMessages(['work_order' => "The Requestor has no employee ID, so the level {$level} billing endorser cannot be found."]);
    }

    /**
     * The billing levels the rounded Billing Amount needs under the CURRENT thresholds (recomputed at every action).
     */
    private function requiredBillingLevels(): int
    {
        return BillingLevels::requiredBillingLevels((int) $this->billing_amount_centavos, BillingThresholdSetting::current());
    }

    /**
     * A billing endorsement action at level $level (issue 23). Inside the lock: separation of duties for the levels
     * required now, the current level's endorser resolved now (422 if not) and matched (403), and on Approve the
     * different-level rule for this billing round. Approve then closes the Work Order when $level >= the levels
     * required now, otherwise moves to the next level, which must resolve now (block and flag). Return goes back one
     * step (level 1 back to the EIC: Implemented). Reject is final.
     *
     * @throws AuthorizationException when the user is not the current level's endorser.
     * @throws ValidationException naming the problem.
     */
    private function endorseBilling(User $endorser, ApprovalAction $action, ?string $remarks, int $level): void
    {
        $required = $this->requiredBillingLevels();

        $this->assertBillingChainSound($required);

        if (! $endorser->actsAsEmployee($this->billingEndorserAtLevel($level)->id)) {
            throw new AuthorizationException("Only the Requestor's current level {$level} billing endorser can act on this Work Order.");
        }

        if ($action === ApprovalAction::Approve) {
            $otherLevel = $this->billingLevelApprovedThisRoundByOtherThan($endorser, $level);

            if ($otherLevel !== null) {
                throw ValidationException::withMessages(['work_order' => "You already approved this Work Order at billing level {$otherLevel} in the current billing round. Separation of duties requires a different endorser at each level."]);
            }
        }

        $toStatus = match ($action) {
            ApprovalAction::Approve => $level >= $required ? WorkOrderStatus::Closed : $this->nextBillingLevel($level + 1),
            ApprovalAction::Return => $level === 1 ? WorkOrderStatus::Implemented : WorkOrderStatus::forBillingLevel($level - 1),
            default => WorkOrderStatus::Rejected,
        };

        app(ApprovalRecorder::class)->transition($this, $endorser, $action, $toStatus, $remarks);
    }

    /**
     * The next billing level, which must resolve right now or the approve is blocked (422 naming the level).
     */
    private function nextBillingLevel(int $level): WorkOrderStatus
    {
        $this->billingEndorserAtLevel($level);

        return WorkOrderStatus::forBillingLevel($level);
    }

    /**
     * Separation of duties within the current billing round (since the Work Order last entered billing level 1 from
     * Implemented, i.e. the EIC's latest validation): the billing level the user already approved, if DIFFERENT from
     * $level. Matched by user ID or employee ID. Approvals from the pre-implementation endorsement and from earlier
     * billing rounds don't count; re-approving the same level is allowed.
     */
    private function billingLevelApprovedThisRoundByOtherThan(User $user, int $level): ?int
    {
        $roundStart = (int) $this->approvalRecords()
            ->where('previous_status', WorkOrderStatus::Implemented->value)
            ->where('resulting_status', WorkOrderStatus::ForBillingEndorsementLvl1->value)
            ->max('id');

        $statuses = $this->approvalRecords()
            ->where('id', '>', $roundStart)
            ->where('action', ApprovalAction::Approve)
            ->where(function ($query) use ($user): void {
                $query->where('actor_user_id', $user->id)
                    ->when($user->employee_id !== null, fn ($query) => $query->orWhere('actor_employee_id', $user->employee_id));
            })
            ->pluck('previous_status');

        foreach ($statuses as $status) {
            $approvedLevel = WorkOrderStatus::tryFrom($status)?->billingLevel();

            if ($approvedLevel !== null && $approvedLevel !== $level) {
                return $approvedLevel;
            }
        }

        return null;
    }

    private function pendingBillingLevel(): ?int
    {
        return WorkOrderStatus::tryFrom((string) $this->getRawOriginal('status'))?->billingLevel();
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

            $locked->assertNoChainConflict();
            $locked->endorserAtLevel(1);
            $locked->endorserAtLevel(2);

            $locked->update([
                'status' => WorkOrderStatus::ForLvl1Endorsement,
                'submitted_at' => now(),
                'endorsement_round_after_record_id' => (int) ApprovalRecord::query()->max('id'),
            ]);

            return $locked;
        });
    }

    /**
     * The current level's endorser approves, returns or rejects: the pre-implementation endorsement (issue 19) or,
     * for a Work Order in billing, the billing endorsement (issue 23, see endorseBilling). The endorser is resolved
     * from the E-Call Requestor's Immediate Head chain at the moment of the action, inside the lock: an org-chart
     * change re-routes a waiting Work Order, and a chain that no longer resolves blocks every action (422) until fixed.
     *
     * @throws AuthorizationException when the user is not the current level's endorser.
     * @throws ValidationException when no endorsement is pending or the current endorser cannot be resolved.
     */
    public static function endorse(WorkOrder $workOrder, User $endorser, ApprovalAction $action, ?string $remarks): self
    {
        return DB::transaction(function () use ($workOrder, $endorser, $action, $remarks): self {
            $locked = static::query()->lockForUpdate()->findOrFail($workOrder->id);
            $billingLevel = $locked->pendingBillingLevel();

            if ($billingLevel !== null) {
                $locked->endorseBilling($endorser, $action, $remarks, $billingLevel);

                return $locked;
            }

            $level = $locked->pendingEndorsementLevel();

            if ($level === null) {
                throw ValidationException::withMessages(['work_order' => 'This Work Order is not waiting for an endorsement.']);
            }

            $locked->assertNoChainConflict();

            if (! $endorser->actsAsEmployee($locked->endorserAtLevel($level)->id)) {
                throw new AuthorizationException("Only the Requestor's current level {$level} endorser can act on this Work Order.");
            }

            $otherLevelApproved = $action === ApprovalAction::Approve ? $locked->levelApprovedThisRoundByOtherThan($endorser, $level) : null;

            if ($otherLevelApproved !== null) {
                throw ValidationException::withMessages(['work_order' => "You already approved this Work Order at level {$otherLevelApproved} in the current endorsement round. Separation of duties requires a different endorser at each level."]);
            }

            app(ApprovalRecorder::class)->transition($locked, $endorser, $action, $locked->status->afterEndorsement($action), $remarks);

            return $locked;
        });
    }

    /**
     * Whether the user is the current endorser for this Work Order's pending level. Used by the policy for the early
     * 403; true when no endorsement is pending, the chain conflicts (separation of duties) or the endorser can't be
     * resolved, so WorkOrder::endorse reports those as 422 to every viewer.
     */
    public function mayBeEndorsedBy(User $user): bool
    {
        $billingLevel = $this->pendingBillingLevel();

        if ($billingLevel !== null) {
            try {
                $this->assertBillingChainSound($this->requiredBillingLevels());
                $head = $this->billingEndorserAtLevel($billingLevel);
            } catch (ValidationException) {
                return true;
            }

            return $user->actsAsEmployee($head->id);
        }

        $level = $this->pendingEndorsementLevel();

        if ($level === null) {
            return true;
        }

        try {
            $this->assertNoChainConflict();
        } catch (ValidationException) {
            return true;
        }

        $head = $this->resolveEndorser($level);

        return $head === null || $user->actsAsEmployee($head->id);
    }

    /**
     * Separation of duties (issue 38): refuse a chain where level 1 and level 2 are the same person, or where either
     * is the Requestor. Walking up from the Requestor, such a chain repeats an employee ID within two steps, which
     * ImmediateHeadChain reports. The equality check after resolving is a backstop. Plain gaps (a missing level) are
     * left to endorserAtLevel's missing-level 422.
     *
     * @throws ValidationException naming the conflict.
     */
    private function assertNoChainConflict(): void
    {
        $requestorEmployeeId = $this->requestorEmployeeId();

        if ($requestorEmployeeId === null) {
            return;
        }

        $chain = app(ImmediateHeadChain::class);

        foreach ([1, 2] as $level) {
            $repeated = $chain->repeatedEmployeeAt($requestorEmployeeId, $level);

            if ($repeated !== null) {
                throw $repeated === $requestorEmployeeId ? static::requestorEndorses($level, $repeated) : static::sameEndorserTwice($repeated);
            }
        }

        $levelOne = $chain->headAtLevel($requestorEmployeeId, 1);
        $levelTwo = $chain->headAtLevel($requestorEmployeeId, 2);

        foreach ([1 => $levelOne, 2 => $levelTwo] as $level => $head) {
            if ($head?->id === $requestorEmployeeId) {
                throw static::requestorEndorses($level, $requestorEmployeeId);
            }
        }

        if ($levelOne !== null && $levelOne->id === $levelTwo?->id) {
            throw static::sameEndorserTwice($levelOne->id);
        }
    }

    private static function requestorEndorses(int $level, string $employeeId): ValidationException
    {
        return ValidationException::withMessages(['work_order' => "The level {$level} endorser would be the Requestor ({$employeeId}). Separation of duties requires someone else."]);
    }

    private static function sameEndorserTwice(string $employeeId): ValidationException
    {
        return ValidationException::withMessages(['work_order' => "Level 1 and level 2 resolve to the same person ({$employeeId}). Separation of duties requires two different endorsers."]);
    }

    /**
     * Separation of duties within one endorsement round (since the latest submit; team-lead refinement of issue 38):
     * the level the user already approved at, if it is a DIFFERENT level from $level. Matched by user ID or employee
     * ID, so a mid-round org change can't let the level 1 approver also approve level 2. Re-approving the same level
     * (after a level 2 Return) is allowed, and Return / Reject are never checked. Null when nothing blocks.
     */
    private function levelApprovedThisRoundByOtherThan(User $user, int $level): ?int
    {
        $statuses = $this->approvalRecords()
            ->where('id', '>', $this->endorsement_round_after_record_id)
            ->where('action', ApprovalAction::Approve)
            ->where(function ($query) use ($user): void {
                $query->where('actor_user_id', $user->id)
                    ->when($user->employee_id !== null, fn ($query) => $query->orWhere('actor_employee_id', $user->employee_id));
            })
            ->pluck('previous_status');

        foreach ($statuses as $status) {
            $approvedLevel = WorkOrderStatus::tryFrom($status)?->endorsementLevel();

            if ($approvedLevel !== null && $approvedLevel !== $level) {
                return $approvedLevel;
            }
        }

        return null;
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
