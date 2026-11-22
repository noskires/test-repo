<?php

namespace App\Http\Resources;

use App\Models\PmTask;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A PM Task with its Site and Equipment Snapshots.
 *
 * @mixin PmTask
 */
class PmTaskResource extends JsonResource
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
            'equipment' => [
                'id' => $this->equipment_id,
                'name' => $this->equipment_name_snapshot,
                'category_id' => $this->equipment_category_id,
                'category' => $this->equipment_category_snapshot,
                'location' => $this->equipment_location_snapshot,
            ],
            'status' => $this->status->value,
            'due_date' => $this->due_date->toDateString(),
            'work_done_date' => $this->work_done_date?->toDateString(),
            'started_at' => $this->started_at,
            'vendor_id' => $this->vendor_id,
            'eic_employee_id' => $this->eic_employee_id,
            'checklist_template_version_id' => $this->checklist_template_version_id,
            'no_checklist' => $this->no_checklist,
            'equipment_removed' => $this->equipment_removed,
        ];
    }
}
