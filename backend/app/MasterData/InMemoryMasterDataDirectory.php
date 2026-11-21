<?php

namespace App\MasterData;

use App\MasterData\Data\Employee;
use App\MasterData\Data\Equipment;
use App\MasterData\Data\EquipmentCategory;
use App\MasterData\Data\Site;

/**
 * Seedable in-memory fake of the master data port, used in tests until the real adapter exists (issue 03).
 * Lists keep insertion order. Seeding an existing ID replaces that entry.
 */
final class InMemoryMasterDataDirectory implements MasterDataDirectory
{
    /** @var array<string, Site> */
    private array $sites = [];

    /** @var array<string, Equipment> */
    private array $equipment = [];

    /** @var array<string, Employee> */
    private array $employees = [];

    /** @var array<string, EquipmentCategory> */
    private array $equipmentCategories = [];

    public function addSite(Site $site): self
    {
        $this->sites[$site->id] = $site;

        return $this;
    }

    public function addEquipment(Equipment $equipment): self
    {
        $this->equipment[$equipment->id] = $equipment;

        return $this;
    }

    public function addEmployee(Employee $employee): self
    {
        $this->employees[$employee->id] = $employee;

        return $this;
    }

    public function addEquipmentCategory(EquipmentCategory $equipmentCategory): self
    {
        $this->equipmentCategories[$equipmentCategory->id] = $equipmentCategory;

        return $this;
    }

    public function findSite(string $siteId): ?Site
    {
        return $this->sites[$siteId] ?? null;
    }

    public function listSites(): array
    {
        return array_values($this->sites);
    }

    public function findEquipment(string $equipmentId): ?Equipment
    {
        return $this->equipment[$equipmentId] ?? null;
    }

    public function listEquipmentAtSite(string $siteId): array
    {
        return array_values(array_filter(
            $this->equipment,
            fn (Equipment $equipment): bool => $equipment->siteId === $siteId,
        ));
    }

    public function findEmployee(string $employeeId): ?Employee
    {
        return $this->employees[$employeeId] ?? null;
    }

    public function listEquipmentCategories(): array
    {
        return array_values($this->equipmentCategories);
    }
}
