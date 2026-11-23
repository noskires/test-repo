<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReviewWorkOrderCompletionWithRemarksRequest;
use App\Http\Requests\ValidateWorkOrderCompletionRequest;
use App\Http\Resources\WorkOrderResource;
use App\Models\WorkOrder;

/**
 * The current Site EIC's review of completed work (issue 21): validate (and set the Billing Amount), return for
 * rework, or reject.
 */
class WorkOrderCompletionValidationController extends Controller
{
    /**
     * Validate: validated quantities and the Billing Amount are stored, and billing endorsement starts.
     */
    public function validateCompletion(ValidateWorkOrderCompletionRequest $request, WorkOrder $workOrder): WorkOrderResource
    {
        return $this->resource(WorkOrder::validateCompletion($workOrder, $request->user(), $request->validatedLines(), $request->remarks()));
    }

    /**
     * Return for rework: For Implementation.
     */
    public function returnCompletion(ReviewWorkOrderCompletionWithRemarksRequest $request, WorkOrder $workOrder): WorkOrderResource
    {
        return $this->resource(WorkOrder::returnCompletion($workOrder, $request->user(), $request->validated('remarks')));
    }

    /**
     * Reject: final.
     */
    public function rejectCompletion(ReviewWorkOrderCompletionWithRemarksRequest $request, WorkOrder $workOrder): WorkOrderResource
    {
        return $this->resource(WorkOrder::rejectCompletion($workOrder, $request->user(), $request->validated('remarks')));
    }

    private function resource(WorkOrder $workOrder): WorkOrderResource
    {
        return WorkOrderResource::make($workOrder->refresh()->load(WorkOrderResource::RELATIONS));
    }
}
