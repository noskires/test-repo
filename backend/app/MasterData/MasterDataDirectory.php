<?php

namespace App\MasterData;

use App\MasterData\Data\Employee;
use App\MasterData\Data\Equipment;
use App\MasterData\Data\EquipmentCategory;
use App\MasterData\Data\Site;

/**
 * Read-only port to the master data: Sites, Equipment, Equipment Categories and employees (ADR 0001, amended).
 *
 * The source database owns it. PSS imports a read-only copy nightly (pss:import-master-data, issue 03) and never
 * edits it. Every slice reads it through this port, keyed by the source IDs. DatabaseMasterDataDirectory serves the
 * imported tables; tests use InMemoryMasterDataDirectory.
 *
 * The find methods also return inactive rows (gone from the source), so existing records still read. The list
 * methods return active rows only.
 */
interface MasterDataDirectory
{
    public function findSite(string $siteId): ?Site;

    /**
     * Active Sites only.
     *
     * @return list<Site>
     */
    public function listSites(): array;

    public function findEquipment(string $equipmentId): ?Equipment;

    /**
     * Active Equipment at the Site only.
     *
     * @return list<Equipment>
     */
    public function listEquipmentAtSite(string $siteId): array;

    public function findEmployee(string $employeeId): ?Employee;

    /**
     * @return list<EquipmentCategory>
     */
    public function listEquipmentCategories(): array;
}
