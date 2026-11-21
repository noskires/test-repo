<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\EquipmentResource;
use App\Http\Resources\SiteResource;
use App\MasterData\MasterDataDirectory;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Read-only Sites and their Equipment, served from the external master data port (ADR 0001).
 */
class SiteController extends Controller
{
    public function __construct(private readonly MasterDataDirectory $directory) {}

    /**
     * List all Sites.
     */
    public function index(): AnonymousResourceCollection
    {
        return SiteResource::collection($this->directory->listSites());
    }

    /**
     * List the Equipment at one Site. Unknown Sites return 404.
     */
    public function equipment(string $siteId): AnonymousResourceCollection
    {
        abort_if($this->directory->findSite($siteId) === null, 404);

        return EquipmentResource::collection($this->directory->listEquipmentAtSite($siteId));
    }
}
