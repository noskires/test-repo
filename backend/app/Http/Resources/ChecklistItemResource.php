<?php

namespace App\Http\Resources;

use App\Models\ChecklistItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ChecklistItem
 */
class ChecklistItemResource extends JsonResource
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
            'position' => $this->position,
            'label' => $this->label,
            'response_type' => $this->response_type->value,
            'mandatory' => $this->mandatory,
            'remarks_required_on_fail' => $this->remarks_required_on_fail,
            'photo_required' => $this->photo_required,
            'acceptable_min' => $this->acceptable_min,
            'acceptable_max' => $this->acceptable_max,
            'acceptable_criteria' => $this->acceptable_criteria,
        ];
    }
}
