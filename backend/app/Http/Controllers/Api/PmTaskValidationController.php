<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ApprovePmTaskRequest;
use App\Http\Requests\ReturnPmTaskRequest;
use App\Http\Resources\ApprovalRecordResource;
use App\Http\Resources\PmTaskResource;
use App\Models\PmTask;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * The EIC's validation of a PM Report (Approve or Return; PM Reports have no Reject), and its Approval Records.
 */
class PmTaskValidationController extends Controller
{
    /**
     * Approve: the task is Completed with the vendor's Work Done Date.
     */
    public function approve(ApprovePmTaskRequest $request, PmTask $pmTask): PmTaskResource
    {
        return PmTaskResource::make(PmTask::approveReport($pmTask, $request->user(), $request->remarks()));
    }

    /**
     * Return: the task goes back to the vendor with the EIC's remarks.
     */
    public function returnToVendor(ReturnPmTaskRequest $request, PmTask $pmTask): PmTaskResource
    {
        return PmTaskResource::make(PmTask::returnReport($pmTask, $request->user(), $request->validated('remarks')));
    }

    /**
     * The task's Approval Records, oldest first. Anyone who can view the task reads them (issue 04, Q6).
     */
    public function approvals(PmTask $pmTask): AnonymousResourceCollection
    {
        Gate::authorize('view', $pmTask);

        return ApprovalRecordResource::collection($pmTask->approvalRecords()->chronological()->get());
    }
}
