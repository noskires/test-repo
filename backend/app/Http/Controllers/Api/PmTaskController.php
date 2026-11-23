<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PmTaskResource;
use App\Models\PmTask;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * The Site Vendor's view of its PM Tasks.
 */
class PmTaskController extends Controller
{
    /**
     * List the PM Tasks assigned to the caller's Vendor, soonest due first. Only vendor staff may call it.
     */
    public function vendorIndex(Request $request): AnonymousResourceCollection
    {
        $vendorId = $request->user()->vendor_id;

        abort_if($vendorId === null, 403);

        return PmTaskResource::collection(
            PmTask::query()->where('vendor_id', $vendorId)->orderBy('due_date')->orderBy('id')->get(),
        );
    }

    /**
     * Show one task with the E-Calls filed from it, oldest first. Vendor staff only see E-Calls routed to their Vendor.
     */
    public function show(Request $request, PmTask $pmTask): PmTaskResource
    {
        Gate::authorize('view', $pmTask);

        return PmTaskResource::make($pmTask->load([
            'eCalls' => fn (HasMany $query) => $query->visibleTo($request->user())->oldest('id'),
        ]));
    }

    /**
     * Start a Scheduled task: it moves to In Progress.
     */
    public function start(PmTask $pmTask): PmTaskResource
    {
        Gate::authorize('work', $pmTask);

        return PmTaskResource::make(PmTask::start($pmTask));
    }
}
