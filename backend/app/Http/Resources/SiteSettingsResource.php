<?php

namespace App\Http\Resources;

use App\MasterData\Data\Employee;
use App\MasterData\Data\Site;
use App\Models\SiteSetting;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A Site's current Site Vendor and EIC. Built from the live Site, its settings row (if any) and the EIC's directory entry.
 */
class SiteSettingsResource extends JsonResource
{
    public function __construct(
        private readonly Site $site,
        private readonly ?SiteSetting $setting,
        private readonly ?Employee $engineer,
    ) {
        parent::__construct($site);
    }

    /**
     * Transform the resource into an array.
     *
     * @return array{site_id: string, site_name: string, vendor: array{id: int, name: string}|null, eic: array{employee_id: string, name: string|null}|null}
     */
    public function toArray(Request $request): array
    {
        $vendor = $this->setting?->vendor;
        $engineerEmployeeId = $this->setting?->eic_employee_id;

        return [
            'site_id' => $this->site->id,
            'site_name' => $this->site->name,
            'vendor' => $vendor === null ? null : ['id' => $vendor->id, 'name' => $vendor->name],
            'eic' => $engineerEmployeeId === null ? null : ['employee_id' => $engineerEmployeeId, 'name' => $this->engineer?->name],
        ];
    }
}
