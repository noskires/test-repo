<?php

namespace Tests\Feature;

use App\MasterData\Data\Employee;
use App\MasterData\Data\Equipment;
use App\MasterData\Data\EquipmentCategory;
use App\MasterData\Data\Site;
use App\MasterData\DatabaseMasterDataDirectory;
use App\MasterData\ImmediateHeadChain;
use App\MasterData\InMemoryMasterDataDirectory;
use App\MasterData\MasterDataDirectory;
use App\MasterData\SampleMasterData;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\MasterDataSampleSeeder;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The database-backed master data port (issue 03), read from the PSS tables the import and the sample seeder fill.
 */
class DatabaseMasterDataDirectoryTest extends TestCase
{
    use RefreshDatabase;

    private DatabaseMasterDataDirectory $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MasterDataSampleSeeder::class);
        $this->directory = new DatabaseMasterDataDirectory;
    }

    public function test_it_implements_the_port(): void
    {
        $this->assertInstanceOf(MasterDataDirectory::class, $this->directory);
    }

    public function test_it_finds_and_lists_sites(): void
    {
        $this->assertEquals(new Site(id: 'SITE-MKT', name: 'Makati Office'), $this->directory->findSite('SITE-MKT'));
        $this->assertNull($this->directory->findSite('SITE-NONE'));
        $this->assertSame(['SITE-CEB', 'SITE-MKT'], array_map(fn (Site $site): string => $site->id, $this->directory->listSites()));
    }

    public function test_it_finds_equipment_with_every_field(): void
    {
        $this->assertEquals(new Equipment(
            id: 'EQ-MKT-GENSET-1',
            siteId: 'SITE-MKT',
            name: 'Genset 1',
            categoryId: 'GENSET',
            location: 'Basement, Genset Room',
            installedOn: new DateTimeImmutable('2022-03-15'),
            operatingStatus: 'operational',
        ), $this->directory->findEquipment('EQ-MKT-GENSET-1'));
        $this->assertNull($this->directory->findEquipment('EQ-NONE'));
    }

    public function test_it_lists_the_equipment_at_a_site(): void
    {
        $this->assertSame(
            ['EQ-MKT-ACU-3F', 'EQ-MKT-GENSET-1'],
            array_map(fn (Equipment $equipment): string => $equipment->id, $this->directory->listEquipmentAtSite('SITE-MKT')),
        );
        $this->assertSame([], $this->directory->listEquipmentAtSite('SITE-NONE'));
    }

    public function test_it_lists_the_equipment_categories_by_stable_code(): void
    {
        $this->assertEquals([
            new EquipmentCategory(id: 'ACU', name: 'ACU'),
            new EquipmentCategory(id: 'ATS', name: 'ATS'),
            new EquipmentCategory(id: 'GENSET', name: 'Genset'),
        ], $this->directory->listEquipmentCategories());
    }

    public function test_find_employee_returns_the_immediate_head_and_position_title(): void
    {
        $this->assertEquals(
            new Employee(id: 'EMP-1001', name: 'Rina Requestor', immediateHeadId: 'EMP-2001', positionTitle: 'Facilities Coordinator'),
            $this->directory->findEmployee(SampleMasterData::REQUESTOR_EMPLOYEE_ID),
        );
        $this->assertNull($this->directory->findEmployee('EMP-NONE'));
    }

    public function test_the_immediate_head_chain_resolves_through_the_database(): void
    {
        $chain = new ImmediateHeadChain($this->directory);

        $this->assertSame('EMP-5001', $chain->headAtLevel(SampleMasterData::REQUESTOR_EMPLOYEE_ID, 4)?->id);
    }

    public function test_inactive_rows_are_still_found_but_not_listed(): void
    {
        DB::table('equipment')->where('source_id', 'EQ-MKT-GENSET-1')->update(['is_active' => false]);
        DB::table('sites')->where('source_id', 'SITE-CEB')->update(['is_active' => false]);
        DB::table('employees')->where('source_id', 'EMP-2001')->update(['is_active' => false]);

        $this->assertFalse($this->directory->findEquipment('EQ-MKT-GENSET-1')->isActive);
        $this->assertSame(['EQ-MKT-ACU-3F'], array_map(fn (Equipment $equipment): string => $equipment->id, $this->directory->listEquipmentAtSite('SITE-MKT')));
        $this->assertFalse($this->directory->findSite('SITE-CEB')->isActive);
        $this->assertSame(['SITE-MKT'], array_map(fn (Site $site): string => $site->id, $this->directory->listSites()));
        $this->assertFalse($this->directory->findEmployee('EMP-2001')->isActive);
    }

    public function test_the_seeder_writes_the_same_sample_data_as_sample_master_data(): void
    {
        $sample = SampleMasterData::load(new InMemoryMasterDataDirectory);

        $this->assertEqualsCanonicalizing($sample->listSites(), $this->directory->listSites());
        $this->assertEqualsCanonicalizing($sample->listEquipmentCategories(), $this->directory->listEquipmentCategories());
        foreach ($sample->listSites() as $site) {
            $this->assertEqualsCanonicalizing($sample->listEquipmentAtSite($site->id), $this->directory->listEquipmentAtSite($site->id));
        }
        foreach (SampleMasterData::EMPLOYEE_IDS as $employeeId) {
            $this->assertEquals($sample->findEmployee($employeeId), $this->directory->findEmployee($employeeId));
        }
        $this->assertSame(count(SampleMasterData::EMPLOYEE_IDS), DB::table('employees')->count());
    }

    public function test_the_seeder_is_safe_to_run_again(): void
    {
        $this->seed(MasterDataSampleSeeder::class);

        $this->assertSame(4, DB::table('equipment')->count());
        $this->assertSame(3, DB::table('equipment_categories')->count());
    }

    public function test_the_database_seeder_seeds_the_sample_master_data_in_local(): void
    {
        DB::table('employees')->delete();
        $this->app['env'] = 'local';
        $this->app->instance(MasterDataDirectory::class, $this->directory);

        $this->seed(DatabaseSeeder::class);

        $this->assertSame(count(SampleMasterData::EMPLOYEE_IDS), DB::table('employees')->count());
    }
}
