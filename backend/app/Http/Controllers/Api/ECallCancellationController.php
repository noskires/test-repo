<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CancelECallRequest;
use App\Http\Resources\ECallResource;
use App\Models\ECall;

/**
 * The Requestor or the Site's current EIC cancels an E-Call before the EIC approves its FSR and BOQ (issue 25).
 */
class ECallCancellationController extends Controller
{
    private const array RELATIONS = ['vendor', 'attachments', 'approvedProposal'];

    /**
     * Cancel: the E-Call is Cancelled (final) and one Approval Record is written.
     */
    public function store(CancelECallRequest $request, ECall $eCall): ECallResource
    {
        return ECallResource::make(ECall::cancel($eCall, $request->user(), $request->validated('remarks'))->load(self::RELATIONS));
    }
}
