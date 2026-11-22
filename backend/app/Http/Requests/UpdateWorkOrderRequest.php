<?php

namespace App\Http\Requests;

use App\Models\WorkOrder;
use Illuminate\Support\Facades\Gate;

/**
 * The creator replaces an Open Work Order's full line set (validated exactly like create) and may add attachments.
 * The Open status is checked under a lock when it is stored (WorkOrder::revise).
 */
class UpdateWorkOrderRequest extends WorkOrderLinesRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return Gate::allows('update', $this->workOrder());
    }

    public function workOrder(): WorkOrder
    {
        /** @var WorkOrder */
        return $this->route('workOrder');
    }

    protected function approvedProposalId(): ?int
    {
        return $this->workOrder()->e_call_proposal_id;
    }
}
