<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreWorkOrderRequest;
use App\Http\Requests\UpdateWorkOrderRequest;
use App\Http\Resources\WorkOrderResource;
use App\Models\ECall;
use App\Models\WorkOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Work Orders: created by the Requestor from an Approved E-Call, edited by their creator while Open.
 */
class WorkOrderController extends Controller
{
    private const array RELATIONS = WorkOrderResource::RELATIONS;

    /**
     * Create the E-Call's Work Order. The E-Call is Closed.
     */
    public function store(StoreWorkOrderRequest $request, ECall $eCall): JsonResponse
    {
        $workOrder = WorkOrder::createFromECall($eCall, $request->user(), $request->workOrderLines(), $request->attachments());

        return WorkOrderResource::make($workOrder->refresh()->load(self::RELATIONS))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Show a Work Order. Visible like its E-Call.
     */
    public function show(WorkOrder $workOrder): WorkOrderResource
    {
        Gate::authorize('view', $workOrder);

        return WorkOrderResource::make($workOrder->load(self::RELATIONS));
    }

    /**
     * Replace an Open Work Order's lines and add attachments. Creator only.
     */
    public function update(UpdateWorkOrderRequest $request, WorkOrder $workOrder): WorkOrderResource
    {
        $revised = WorkOrder::revise($workOrder, $request->user(), $request->workOrderLines(), $request->attachments());

        return WorkOrderResource::make($revised->refresh()->load(self::RELATIONS));
    }
}
