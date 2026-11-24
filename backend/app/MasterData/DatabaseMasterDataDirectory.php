<?php

namespace App\MasterData;

use App\MasterData\Data\Employee;
use App\MasterData\Data\Equipment;
use App\MasterData\Data\EquipmentCategory;
use App\MasterData\Data\Site;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Serves the master data port from the PSS copy that pss:import-master-data keeps up to date (issue 03).
 * Read-only: nothing here writes. The find methods include inactive rows; the list methods return active rows only.
 */
final class DatabaseMasterDataDirectory implements MasterDataDirectory
{
    public function findSite(string $siteId): ?Site
    {
        $row = DB::table('sites')->where('source_id', $siteId)->first();

        return $row === null ? null : $this->site($row);
    }

    public function listSites(): array
    {
        return DB::table('sites')
            ->where('is_active', true)
            ->orderBy('name')
            ->orderBy('source_id')
            ->get()
            ->map(fn (object $row): Site => $this->site($row))
            ->all();
    }

    public function findEquipment(string $equipmentId): ?Equipment
    {
        $row = DB::table('equipment')->where('source_id', $equipmentId)->first();

        return $row === null ? null : $this->equipment($row);
    }

    public function listEquipmentAtSite(string $siteId): array
    {
        return DB::table('equipment')
            ->where('site_source_id', $siteId)
            ->where('is_active', true)
            ->orderBy('name')
            ->orderBy('source_id')
            ->get()
            ->map(fn (object $row): Equipment => $this->equipment($row))
            ->all();
    }

    public function findEmployee(string $employeeId): ?Employee
    {
        $row = DB::table('employees')->where('source_id', $employeeId)->first();

        return $row === null ? null : new Employee(
            id: $row->source_id,
            name: $row->name,
            immediateHeadId: $row->immediate_head_id,
            positionTitle: $row->position_title,
            isActive: (bool) $row->is_active,
        );
    }

    public function listEquipmentCategories(): array
    {
        return DB::table('equipment_categories')
            ->orderBy('name')
            ->get()
            ->map(fn (object $row): EquipmentCategory => new EquipmentCategory(id: $row->code, name: $row->name))
            ->all();
    }

    private function site(object $row): Site
    {
        return new Site(id: $row->source_id, name: $row->name, isActive: (bool) $row->is_active);
    }

    private function equipment(object $row): Equipment
    {
        return new Equipment(
            id: $row->source_id,
            siteId: $row->site_source_id,
            name: $row->name,
            categoryId: $row->equipment_category_code,
            location: $row->location,
            installedOn: $row->installed_on === null ? null : new DateTimeImmutable(substr((string) $row->installed_on, 0, 10)),
            operatingStatus: $row->operating_status,
            isActive: (bool) $row->is_active,
        );
    }
}
