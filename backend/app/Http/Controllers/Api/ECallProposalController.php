<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreECallProposalRequest;
use App\Http\Resources\ECallProposalResource;
use App\Models\ECall;
use App\Models\ECallProposal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * The Site Vendor's FSR + BOQ revisions for an E-Call.
 */
class ECallProposalController extends Controller
{
    /**
     * List every revision, newest first. Same visibility as the E-Call itself.
     */
    public function index(ECall $eCall): AnonymousResourceCollection
    {
        Gate::authorize('view', $eCall);

        return ECallProposalResource::collection(
            $eCall->proposals()->with('lines')->orderByDesc('revision')->get(),
        );
    }

    /**
     * Submit the next revision. The E-Call moves to For EIC Review.
     */
    public function store(StoreECallProposalRequest $request, ECall $eCall): JsonResponse
    {
        $proposal = ECallProposal::submit(
            eCall: $eCall,
            submitter: $request->user(),
            assessmentNotes: $request->validated('assessment_notes'),
            fsr: $request->fsr(),
            lines: $request->boqLines(),
        );

        return ECallProposalResource::make($proposal->refresh()->load('lines'))
            ->response()
            ->setStatusCode(201);
    }
}
