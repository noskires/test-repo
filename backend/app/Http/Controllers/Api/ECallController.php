<?php

namespace App\Http\Controllers\Api;

use App\Enums\ECallPriority;
use App\Enums\ECallStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreECallRequest;
use App\Http\Resources\ECallResource;
use App\Models\ECall;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * E-Calls filed by any signed-in Requestor. Vendor staff only see E-Calls routed to their Vendor.
 */
class ECallController extends Controller
{
    /**
     * List the E-Calls the user may see, newest first, optionally filtered by site, status and priority.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'site_id' => ['sometimes', 'string', 'max:255'],
            'status' => ['sometimes', Rule::enum(ECallStatus::class)],
            'priority' => ['sometimes', Rule::enum(ECallPriority::class)],
        ]);

        $eCalls = ECall::query()
            ->visibleTo($request->user())
            ->when($request->filled('site_id'), fn ($query) => $query->where('site_id', $request->string('site_id')->value()))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')->value()))
            ->when($request->filled('priority'), fn ($query) => $query->where('priority', $request->string('priority')->value()))
            ->with(['vendor', 'attachments'])
            ->latest('id')
            ->get();

        return ECallResource::collection($eCalls);
    }

    /**
     * File an E-Call, routed to the Site's current Site Vendor and EIC.
     */
    public function store(StoreECallRequest $request): JsonResponse
    {
        $equipment = $request->equipment();

        $eCall = ECall::file(
            requestor: $request->user(),
            site: $request->site(),
            equipment: $equipment,
            equipmentCategoryName: $equipment === null ? null : $request->equipmentCategoryName($equipment),
            routing: $request->routing(),
            details: $request->eCallDetails(),
            files: $request->attachments(),
        );

        return ECallResource::make($eCall->refresh()->load(['vendor', 'attachments']))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Show one E-Call. Vendor staff get 403 for another Vendor's E-Call.
     */
    public function show(ECall $eCall): ECallResource
    {
        Gate::authorize('view', $eCall);

        return ECallResource::make($eCall->load(['vendor', 'attachments']));
    }
}
