<?php

namespace App\Http\Controllers\Api;

use App\Enums\ApprovalAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\EndorseWorkOrderRequest;
use App\Http\Requests\EndorseWorkOrderWithRemarksRequest;
use App\Http\Resources\ApprovalRecordResource;
use App\Http\Resources\WorkOrderResource;
use App\Models\WorkOrder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * The 2-level endorsement of a Work Order before implementation (submit, approve, return, reject) and its
 * Approval Records.
 */
class WorkOrderEndorsementController extends Controller
{
    private const array RELATIONS = WorkOrderResource::RELATIONS;

    /**
     * The creator submits an Open Work Order: For LVL1 Endorsement.
     */
    public function submit(Request $request, WorkOrder $workOrder): WorkOrderResource
    {
        Gate::authorize('view', $workOrder);
        Gate::authorize('submit', $workOrder);

        return $this->resource(WorkOrder::submit($workOrder, $request->user()));
    }

    /**
     * Approve: level 1 moves to level 2; level 2 authorises implementation.
     */
    public function approve(EndorseWorkOrderRequest $request, WorkOrder $workOrder): WorkOrderResource
    {
        return $this->resource(WorkOrder::endorse($workOrder, $request->user(), ApprovalAction::Approve, $request->remarks()));
    }

    /**
     * Return one step: level 1 back to the creator (Open), level 2 back to level 1.
     */
    public function returnOneStep(EndorseWorkOrderWithRemarksRequest $request, WorkOrder $workOrder): WorkOrderResource
    {
        return $this->resource(WorkOrder::endorse($workOrder, $request->user(), ApprovalAction::Return, $request->validated('remarks')));
    }

    /**
     * Reject: the Work Order is permanently ended.
     */
    public function reject(EndorseWorkOrderWithRemarksRequest $request, WorkOrder $workOrder): WorkOrderResource
    {
        return $this->resource(WorkOrder::endorse($workOrder, $request->user(), ApprovalAction::Reject, $request->validated('remarks')));
    }

    /**
     * The Work Order's Approval Records, oldest first. Visible like the Work Order.
     */
    public function approvals(WorkOrder $workOrder): AnonymousResourceCollection
    {
        Gate::authorize('view', $workOrder);

        return ApprovalRecordResource::collection($workOrder->approvalRecords()->chronological()->get());
    }

    private function resource(WorkOrder $workOrder): WorkOrderResource
    {
        return WorkOrderResource::make($workOrder->refresh()->load(self::RELATIONS));
    }
}
