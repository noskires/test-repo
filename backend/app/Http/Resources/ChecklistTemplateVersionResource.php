<?php

namespace App\Http\Resources;

use App\Models\ChecklistTemplateVersion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ChecklistTemplateVersion
 */
class ChecklistTemplateVersionResource extends JsonResource
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
            'checklist_template_id' => $this->checklist_template_id,
            'version_number' => $this->version_number,
            'created_at' => $this->created_at,
            'items' => ChecklistItemResource::collection($this->whenLoaded('items')),
        ];
    }
}
