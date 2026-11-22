<?php

namespace App\Approvals;

use App\Models\ApprovalRecord;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Default ApprovalSubject implementation: records are morphed on "subject", and the status lives in "status".
 */
trait HasApprovalRecords
{
    /**
     * @return MorphMany<ApprovalRecord, $this>
     */
    public function approvalRecords(): MorphMany
    {
        return $this->morphMany(ApprovalRecord::class, 'subject');
    }

    public function approvalStatusAttribute(): string
    {
        return 'status';
    }
}
