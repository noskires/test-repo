<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ApproveECallRequest;
use App\Http\Requests\DecideECallWithRemarksRequest;
use App\Http\Resources\ApprovalRecordResource;
use App\Http\Resources\ECallResource;
use App\Models\ECall;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * The Site's current EIC reviews an E-Call's FSR and BOQ (Approve, Return or Reject), and its Approval Records.
 */
class ECallReviewController extends Controller
{
    private const array RELATIONS = ['vendor', 'attachments', 'approvedProposal'];

    /**
     * Approve: the E-Call is Approved and its latest FSR + BOQ revision is recorded as the approved one.
     */
    public function approve(ApproveECallRequest $request, ECall $eCall): ECallResource
    {
        return ECallResource::make(ECall::approveProposal($eCall, $request->user(), $request->remarks())->load(self::RELATIONS));
    }

    /**
     * Return: the FSR + BOQ goes back to the vendor with the EIC's remarks.
     */
    public function returnToVendor(DecideECallWithRemarksRequest $request, ECall $eCall): ECallResource
    {
        return ECallResource::make(ECall::returnProposal($eCall, $request->user(), $request->validated('remarks'))->load(self::RELATIONS));
    }

    /**
     * Reject: the E-Call is permanently ended.
     */
    public function reject(DecideECallWithRemarksRequest $request, ECall $eCall): ECallResource
    {
        return ECallResource::make(ECall::rejectProposal($eCall, $request->user(), $request->validated('remarks'))->load(self::RELATIONS));
    }

    /**
     * The E-Call's Approval Records, oldest first. Visible to anyone who can view the E-Call.
     */
    public function approvals(ECall $eCall): AnonymousResourceCollection
    {
        Gate::authorize('view', $eCall);

        return ApprovalRecordResource::collection($eCall->approvalRecords()->chronological()->get());
    }
}
