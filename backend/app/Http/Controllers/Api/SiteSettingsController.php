<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateSiteEicRequest;
use App\Http\Requests\UpdateSitePmFrequencyRequest;
use App\Http\Requests\UpdateSiteVendorRequest;
use App\Http\Resources\SiteSettingsResource;
use App\MasterData\Data\Site;
use App\MasterData\MasterDataDirectory;
use App\Models\SiteSetting;

/**
 * A Site's Site Vendor, EIC and PM Frequency. Changing them needs the manage-site-settings Gate.
 */
class SiteSettingsController extends Controller
{
    public function __construct(private readonly MasterDataDirectory $directory) {}

    /**
     * Show the current Site Vendor and EIC (null when unassigned).
     */
    public function show(string $siteId): SiteSettingsResource
    {
        $site = $this->findSiteOrFail($siteId);

        return $this->settingsResource($site, SiteSetting::query()->with('vendor')->firstWhere('site_id', $site->id));
    }

    /**
     * Assign or replace the Site Vendor.
     */
    public function updateVendor(UpdateSiteVendorRequest $request, string $siteId): SiteSettingsResource
    {
        $site = $this->findSiteOrFail($siteId);

        return $this->settingsResource($site, SiteSetting::assignVendor($site, $request->vendor(), $request->user()));
    }

    /**
     * Assign or replace the Site's EIC.
     */
    public function updateEic(UpdateSiteEicRequest $request, string $siteId): SiteSettingsResource
    {
        $site = $this->findSiteOrFail($siteId);

        return $this->settingsResource($site, SiteSetting::assignEic($site, $request->engineer(), $request->user()));
    }

    /**
     * Set or replace the Site's PM Frequency.
     */
    public function updatePmFrequency(UpdateSitePmFrequencyRequest $request, string $siteId): SiteSettingsResource
    {
        $site = $this->findSiteOrFail($siteId);

        return $this->settingsResource($site, SiteSetting::assignPmFrequency($site, $request->frequency(), $request->user()));
    }

    private function findSiteOrFail(string $siteId): Site
    {
        $site = $this->directory->findSite($siteId);

        abort_if($site === null, 404);

        return $site;
    }

    private function settingsResource(Site $site, ?SiteSetting $setting): SiteSettingsResource
    {
        $engineer = $setting?->eic_employee_id === null ? null : $this->directory->findEmployee($setting->eic_employee_id);

        return new SiteSettingsResource($site, $setting, $engineer);
    }
}
