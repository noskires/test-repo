<?php

namespace App\Http\Resources;

use App\MasterData\Data\Equipment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Equipment
 */
class EquipmentResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array{id: string, site_id: string, name: string, category_id: string, location: ?string, installed_on: ?string, operating_status: ?string}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'site_id' => $this->siteId,
            'name' => $this->name,
            'category_id' => $this->categoryId,
            'location' => $this->location,
            'installed_on' => $this->installedOn?->format('Y-m-d'),
            'operating_status' => $this->operatingStatus,
        ];
    }
}
