<?php

namespace App\MasterData;

use App\MasterData\Data\Employee;
use App\MasterData\Data\Equipment;
use App\MasterData\Data\EquipmentCategory;
use App\MasterData\Data\Site;

/**
 * Read-only port to the external system that owns Sites, Equipment, Equipment Categories and employees (ADR 0001).
 *
 * PSS never stores this master data. Every slice reads it through this port, keyed by the external IDs.
 * Concrete HTTP adapters live in App\MasterData\Adapters (issue 03). Tests use InMemoryMasterDataDirectory.
 */
interface MasterDataDirectory
{
    public function findSite(string $siteId): ?Site;

    /**
     * @return list<Site>
     */
    public function listSites(): array;

    public function findEquipment(string $equipmentId): ?Equipment;

    /**
     * @return list<Equipment>
     */
    public function listEquipmentAtSite(string $siteId): array;

    public function findEmployee(string $employeeId): ?Employee;

    /**
     * @return list<EquipmentCategory>
     */
    public function listEquipmentCategories(): array;
}
