<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CancelWorkOrderRequest;
use App\Http\Resources\WorkOrderResource;
use App\Models\WorkOrder;

/**
 * The creator or the current Site EIC cancels a Work Order before implementation is authorised (issue 25).
 */
class WorkOrderCancellationController extends Controller
{
    /**
     * Cancel: the Work Order is Cancelled (final) and one Approval Record is written. Its E-Call stays Closed.
     */
    public function store(CancelWorkOrderRequest $request, WorkOrder $workOrder): WorkOrderResource
    {
        return WorkOrderResource::make(WorkOrder::cancel($workOrder, $request->user(), $request->validated('remarks'))->refresh()->load(WorkOrderResource::RELATIONS));
    }
}
