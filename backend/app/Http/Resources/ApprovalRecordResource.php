<?php

namespace App\Http\Resources;

use App\Models\ApprovalRecord;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One Approval Record. Shared by every workflow that logs approvals (PM Tasks, E-Calls, Work Orders).
 *
 * @mixin ApprovalRecord
 */
class ApprovalRecordResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array{id: int, action: string, actor: array{user_id: int, employee_id: ?string}, remarks: ?string, previous_status: string, resulting_status: string, created_at: mixed}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'action' => $this->action->value,
            'actor' => ['user_id' => $this->actor_user_id, 'employee_id' => $this->actor_employee_id],
            'remarks' => $this->remarks,
            'previous_status' => $this->previous_status,
            'resulting_status' => $this->resulting_status,
            'created_at' => $this->created_at,
        ];
    }
}
