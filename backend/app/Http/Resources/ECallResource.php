<?php

namespace App\Http\Resources;

use App\Models\ECall;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An E-Call as filed: Site and Equipment come from their Snapshots, the Vendor and EIC from filing time.
 *
 * @mixin ECall
 */
class ECallResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'site' => ['id' => $this->site_id, 'name' => $this->site_name_snapshot],
            'equipment' => $this->equipment_id === null ? null : [
                'id' => $this->equipment_id,
                'name' => $this->equipment_name_snapshot,
                'category_id' => $this->equipment_category_id,
                'category' => $this->equipment_category_snapshot,
                'location' => $this->equipment_location_snapshot,
            ],
            'nature_of_issue' => $this->nature_of_issue,
            'description' => $this->description,
            'priority' => $this->priority->value,
            'status' => $this->status->value,
            'requestor' => ['user_id' => $this->requestor_user_id, 'employee_id' => $this->requestor_employee_id],
            'vendor' => ['id' => $this->vendor_id, 'name' => $this->vendor->name],
            'eic_employee_id' => $this->eic_employee_id,
            'pm_task_id' => $this->pm_task_id,
            'approved_proposal' => $this->approved_proposal_id === null ? null : [
                'id' => $this->approved_proposal_id,
                'revision' => $this->approvedProposal->revision,
            ],
            'attachments' => ECallAttachmentResource::collection($this->attachments),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
