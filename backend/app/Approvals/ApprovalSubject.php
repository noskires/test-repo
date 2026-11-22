<?php

namespace App\Approvals;

use App\Models\ApprovalRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * A record whose workflow steps are logged as Approval Records (PM Tasks now; E-Calls and Work Orders later).
 * Use the HasApprovalRecords trait and register the model's alias in the morph map (AppServiceProvider).
 */
interface ApprovalSubject
{
    /**
     * @return MorphMany<ApprovalRecord, Model>
     */
    public function approvalRecords(): MorphMany;

    /**
     * The attribute holding the subject's workflow status (a backed enum cast).
     */
    public function approvalStatusAttribute(): string;
}
