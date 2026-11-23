<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\DeclareWorkOrderCompletionRequest;
use App\Http\Resources\WorkOrderCompletionResource;
use App\Http\Resources\WorkOrderResource;
use App\Models\WorkOrder;
use App\Models\WorkOrderCompletion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * The Site Vendor's side of implementation (issue 20): its Work Orders, and completion declarations.
 */
class WorkOrderCompletionController extends Controller
{
    private const array RELATIONS = ['lines.workOrderLine', 'files'];

    /**
     * Work Orders whose E-Call was routed to the caller's Vendor, newest first. Vendor staff only.
     */
    public function vendorIndex(Request $request): AnonymousResourceCollection
    {
        $vendorId = $request->user()->vendor_id;

        abort_if($vendorId === null, 403);

        return WorkOrderResource::collection(
            WorkOrder::query()
                ->whereHas('eCall', fn ($query) => $query->where('vendor_id', $vendorId))
                ->with(['lines', 'attachments', 'proposal'])
                ->orderByDesc('id')
                ->get(),
        );
    }

    /**
     * Declare completion: the Work Order becomes Implemented.
     */
    public function store(DeclareWorkOrderCompletionRequest $request, WorkOrder $workOrder): JsonResponse
    {
        $completion = WorkOrderCompletion::submit(
            $workOrder,
            $request->user(),
            $request->validated('notes'),
            $request->completionLines(),
            $request->documents(),
            $request->photos(),
        );

        return WorkOrderCompletionResource::make($completion->refresh()->load(self::RELATIONS)->setAttribute('is_current', true))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Every completion revision, newest (current) first. Visible like the Work Order.
     */
    public function index(WorkOrder $workOrder): AnonymousResourceCollection
    {
        Gate::authorize('view', $workOrder);

        $completions = $workOrder->completions()->with(self::RELATIONS)->orderByDesc('revision')->get();
        $currentRevision = $completions->max('revision');

        return WorkOrderCompletionResource::collection(
            $completions->each(fn (WorkOrderCompletion $completion) => $completion->setAttribute('is_current', $completion->revision === $currentRevision)),
        );
    }
}
