<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePmTaskECallRequest;
use App\Http\Resources\ECallResource;
use App\Models\ECall;
use App\Models\PmTask;
use Illuminate\Http\JsonResponse;

/**
 * The EIC files an E-Call from a PM Task's findings. The PM Task itself is not changed.
 */
class PmTaskECallController extends Controller
{
    /**
     * File an E-Call for the task's Site and Equipment, referencing the task, routed like any other E-Call.
     */
    public function store(StorePmTaskECallRequest $request, PmTask $pmTask): JsonResponse
    {
        $equipment = $request->equipment();

        $eCall = ECall::file(
            requestor: $request->user(),
            site: $request->site(),
            equipment: $equipment,
            equipmentCategoryName: $request->equipmentCategoryName($equipment),
            routing: $request->routing(),
            details: $request->eCallDetails(),
            files: $request->attachments(),
        );

        return ECallResource::make($eCall->refresh()->load(['vendor', 'attachments', 'approvedProposal']))
            ->response()
            ->setStatusCode(201);
    }
}
