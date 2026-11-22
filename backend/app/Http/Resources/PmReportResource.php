<?php

namespace App\Http\Resources;

use App\Billing\BoqQuantity;
use App\Models\PmReport;
use App\Models\PmReportAttachment;
use App\Models\PmReportMaterial;
use App\Models\PmReportResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One PM Report revision. Storage paths stay private.
 *
 * @mixin PmReport
 */
class PmReportResource extends JsonResource
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
            'pm_task_id' => $this->pm_task_id,
            'revision' => $this->revision,
            'is_current' => (bool) $this->is_current,
            'checklist_template_version_id' => $this->checklist_template_version_id,
            'work_done_date' => $this->work_done_date->toDateString(),
            'findings' => $this->findings,
            'responses' => $this->responses->map(fn (PmReportResponse $response): array => [
                'checklist_item_id' => $response->checklist_item_id,
                'value' => $response->value,
                'remarks' => $response->remarks,
                'is_failed' => $response->is_failed,
            ])->all(),
            'materials' => $this->materials->map(fn (PmReportMaterial $material): array => [
                'description' => $material->description,
                'quantity' => BoqQuantity::fromThousandths($material->quantity_thousandths),
            ])->all(),
            'attachments' => $this->attachments->whereNull('checklist_item_id')->values()->map($this->attachment(...))->all(),
            'photos' => $this->attachments->whereNotNull('checklist_item_id')->values()->map($this->attachment(...))->all(),
            'submitted_by_user_id' => $this->submitted_by_user_id,
            'submitted_at' => $this->submitted_at,
        ];
    }

    /**
     * @return array{id: int, checklist_item_id: ?int, original_name: string, mime_type: string, size_bytes: int}
     */
    private function attachment(PmReportAttachment $attachment): array
    {
        return [
            'id' => $attachment->id,
            'checklist_item_id' => $attachment->checklist_item_id,
            'original_name' => $attachment->original_name,
            'mime_type' => $attachment->mime_type,
            'size_bytes' => $attachment->size_bytes,
        ];
    }
}
