<?php

namespace Tests\Unit\MasterData;

use App\MasterData\Data\Employee;
use App\MasterData\Data\Equipment;
use App\MasterData\Data\EquipmentCategory;
use App\MasterData\Data\Site;
use App\MasterData\InMemoryMasterDataDirectory;
use App\MasterData\MasterDataDirectory;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

class InMemoryMasterDataDirectoryTest extends TestCase
{
    private function seededDirectory(): InMemoryMasterDataDirectory
    {
        return (new InMemoryMasterDataDirectory)
            ->addSite(new Site(id: 'S-1', name: 'Makati Office'))
            ->addSite(new Site(id: 'S-2', name: 'Cebu Site'))
            ->addEquipmentCategory(new EquipmentCategory(id: 'C-1', name: 'Genset'))
            ->addEquipmentCategory(new EquipmentCategory(id: 'C-2', name: 'ACU'))
            ->addEquipment(new Equipment(
                id: 'E-1',
                siteId: 'S-1',
                name: 'Genset 1',
                categoryId: 'C-1',
                location: 'Basement, Genset Room',
                installedOn: new DateTimeImmutable('2024-01-15'),
                operatingStatus: 'operational',
            ))
            ->addEquipment(new Equipment(id: 'E-2', siteId: 'S-1', name: 'ACU 3F', categoryId: 'C-2'))
            ->addEquipment(new Equipment(id: 'E-3', siteId: 'S-2', name: 'Genset Cebu', categoryId: 'C-1'))
            ->addEmployee(new Employee(id: 'P-1', name: 'Ana Reyes', immediateHeadId: 'P-2'))
            ->addEmployee(new Employee(id: 'P-2', name: 'Ben Cruz'));
    }

    public function test_it_implements_the_port(): void
    {
        $this->assertInstanceOf(MasterDataDirectory::class, new InMemoryMasterDataDirectory);
    }

    public function test_an_empty_directory_lists_nothing(): void
    {
        $directory = new InMemoryMasterDataDirectory;

        $this->assertSame([], $directory->listSites());
        $this->assertSame([], $directory->listEquipmentAtSite('S-1'));
        $this->assertSame([], $directory->listEquipmentCategories());
    }

    public function test_it_finds_and_lists_seeded_sites(): void
    {
        $directory = $this->seededDirectory();

        $this->assertSame('Makati Office', $directory->findSite('S-1')?->name);
        $this->assertSame(['S-1', 'S-2'], array_map(fn (Site $site): string => $site->id, $directory->listSites()));
    }

    public function test_it_finds_seeded_equipment_with_all_its_details(): void
    {
        $equipment = $this->seededDirectory()->findEquipment('E-1');

        $this->assertNotNull($equipment);
        $this->assertSame('S-1', $equipment->siteId);
        $this->assertSame('Genset 1', $equipment->name);
        $this->assertSame('C-1', $equipment->categoryId);
        $this->assertSame('Basement, Genset Room', $equipment->location);
        $this->assertSame('2024-01-15', $equipment->installedOn?->format('Y-m-d'));
        $this->assertSame('operational', $equipment->operatingStatus);
    }

    public function test_it_lists_only_the_equipment_at_the_given_site(): void
    {
        $directory = $this->seededDirectory();

        $this->assertSame(
            ['E-1', 'E-2'],
            array_map(fn (Equipment $equipment): string => $equipment->id, $directory->listEquipmentAtSite('S-1')),
        );
        $this->assertSame(
            ['E-3'],
            array_map(fn (Equipment $equipment): string => $equipment->id, $directory->listEquipmentAtSite('S-2')),
        );
        $this->assertSame([], $directory->listEquipmentAtSite('S-unknown'));
    }

    public function test_it_finds_an_employee_with_their_immediate_head_id(): void
    {
        $directory = $this->seededDirectory();

        $this->assertSame('Ana Reyes', $directory->findEmployee('P-1')?->name);
        $this->assertSame('P-2', $directory->findEmployee('P-1')?->immediateHeadId);
        $this->assertNull($directory->findEmployee('P-2')?->immediateHeadId);
    }

    public function test_it_lists_seeded_equipment_categories(): void
    {
        $this->assertSame(
            ['Genset', 'ACU'],
            array_map(
                fn (EquipmentCategory $category): string => $category->name,
                $this->seededDirectory()->listEquipmentCategories(),
            ),
        );
    }

    public function test_unknown_ids_return_null(): void
    {
        $directory = $this->seededDirectory();

        $this->assertNull($directory->findSite('S-unknown'));
        $this->assertNull($directory->findEquipment('E-unknown'));
        $this->assertNull($directory->findEmployee('P-unknown'));
    }

    public function test_seeding_the_same_id_again_replaces_the_entry(): void
    {
        $directory = $this->seededDirectory()->addSite(new Site(id: 'S-1', name: 'Makati HQ'));

        $this->assertSame('Makati HQ', $directory->findSite('S-1')?->name);
        $this->assertCount(2, $directory->listSites());
    }
}
