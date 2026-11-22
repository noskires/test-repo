<?php

namespace App\Approvals;

use App\Enums\ApprovalAction;
use App\Models\ApprovalRecord;
use App\Models\User;
use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Moves an ApprovalSubject to a new status and writes its Approval Record in one transaction, with the subject
 * row locked, so a status change and its record can never diverge. It checks nothing about the workflow:
 * each workflow validates its own transition (ideally under the same lock) before calling it.
 */
final class ApprovalRecorder
{
    /**
     * @param  Model&ApprovalSubject  $subject  Its in-memory status is updated to match on success.
     */
    public function transition(
        Model&ApprovalSubject $subject,
        User $actor,
        ApprovalAction $action,
        BackedEnum $toStatus,
        ?string $remarks = null,
    ): ApprovalRecord {
        return DB::transaction(function () use ($subject, $actor, $action, $toStatus, $remarks): ApprovalRecord {
            /** @var Model&ApprovalSubject $locked */
            $locked = $subject->newQuery()->lockForUpdate()->findOrFail($subject->getKey());
            $statusAttribute = $locked->approvalStatusAttribute();
            $previousStatus = $locked->getAttribute($statusAttribute);

            $locked->setAttribute($statusAttribute, $toStatus);

            if (! $locked->save()) {
                throw new RuntimeException('The '.$locked->getMorphClass()." [{$locked->getKey()}] status change was not saved.");
            }

            $record = $locked->approvalRecords()->create([
                'action' => $action,
                'actor_user_id' => $actor->getKey(),
                'actor_employee_id' => $actor->employee_id,
                'remarks' => $remarks,
                'previous_status' => $previousStatus instanceof BackedEnum ? (string) $previousStatus->value : (string) $previousStatus,
                'resulting_status' => (string) $toStatus->value,
            ]);

            $subject->setRawAttributes($locked->getAttributes(), true);

            return $record->setRelation('subject', $locked);
        });
    }
}
