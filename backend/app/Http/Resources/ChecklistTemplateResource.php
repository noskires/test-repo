<?php

namespace App\Http\Resources;

use App\Models\ChecklistTemplate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ChecklistTemplate
 */
class ChecklistTemplateResource extends JsonResource
{
    /**
     * Transform the resource into an array. The current version (with items) is included only when loaded with its items.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'key' => $this->key,
            'name' => $this->name,
            'equipment_category_id' => $this->equipment_category_id,
            'is_active' => $this->is_active,
            'current_version_number' => $this->currentVersion?->version_number,
            'current_version' => $this->when(
                $this->currentVersion?->relationLoaded('items') === true,
                fn (): ChecklistTemplateVersionResource => ChecklistTemplateVersionResource::make($this->currentVersion),
            ),
        ];
    }
}
