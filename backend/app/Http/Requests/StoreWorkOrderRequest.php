<?php

namespace App\Http\Requests;

use App\Models\ECall;
use Illuminate\Support\Facades\Gate;

/**
 * The E-Call's Requestor creates its Work Order from the approved BOQ revision. Status and "no Work Order yet" are
 * checked under a lock when it is stored (WorkOrder::createFromECall).
 */
class StoreWorkOrderRequest extends WorkOrderLinesRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return Gate::allows('createWorkOrder', $this->eCall());
    }

    public function eCall(): ECall
    {
        /** @var ECall */
        return $this->route('eCall');
    }

    protected function approvedProposalId(): ?int
    {
        return $this->eCall()->approved_proposal_id;
    }
}
