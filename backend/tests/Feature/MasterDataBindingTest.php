<?php

namespace Tests\Feature;

use App\MasterData\Data\Equipment;
use App\MasterData\Data\EquipmentCategory;
use App\MasterData\DatabaseMasterDataDirectory;
use App\MasterData\ImmediateHeadChain;
use App\MasterData\InMemoryMasterDataDirectory;
use App\MasterData\MasterDataDirectory;
use App\MasterData\SampleMasterData;
use App\Models\User;
use App\Providers\AppServiceProvider;
use Database\Seeders\MasterDataSampleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MasterDataBindingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Switch the app to another environment and re-run the provider registration from a clean binding.
     */
    private function registerProvidersIn(string $environment): void
    {
        unset($this->app[MasterDataDirectory::class]);
        $this->app['env'] = $environment;

        (new AppServiceProvider($this->app))->register();
    }

    public function test_local_resolves_the_database_directory_serving_the_seeded_sample_data(): void
    {
        $this->registerProvidersIn('local');
        $this->seed(MasterDataSampleSeeder::class);

        $directory = $this->app->make(MasterDataDirectory::class);

        $this->assertInstanceOf(DatabaseMasterDataDirectory::class, $directory);
        $this->assertSame($directory, $this->app->make(MasterDataDirectory::class));
        $this->assertNotSame([], $directory->listSites());
        $this->assertNotNull($directory->findEmployee(SampleMasterData::REQUESTOR_EMPLOYEE_ID));
    }

    public function test_local_serves_the_sample_sites_from_the_api(): void
    {
        $this->registerProvidersIn('local');
        $this->seed(MasterDataSampleSeeder::class);
        $firstSite = $this->app->make(MasterDataDirectory::class)->listSites()[0];

        $this->actingAs(User::factory()->create());

        $this->getJson('/api/sites')
            ->assertOk()
            ->assertJsonPath('data.0.id', $firstSite->id);
        $this->getJson("/api/sites/{$firstSite->id}/equipment")
            ->assertOk()
            ->assertJsonPath('data.0.site_id', $firstSite->id);
    }

    public function test_testing_resolves_an_empty_fake(): void
    {
        $this->registerProvidersIn('testing');

        $directory = $this->app->make(MasterDataDirectory::class);

        $this->assertInstanceOf(InMemoryMasterDataDirectory::class, $directory);
        $this->assertSame([], $directory->listSites());
        $this->assertSame([], $directory->listEquipmentCategories());
        $this->assertNull($directory->findEmployee(SampleMasterData::REQUESTOR_EMPLOYEE_ID));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function nonTestingEnvironments(): array
    {
        return [
            'local' => ['local'],
            'production' => ['production'],
            'staging' => ['staging'],
        ];
    }

    #[DataProvider('nonTestingEnvironments')]
    public function test_every_environment_except_testing_gets_the_database_directory(string $environment): void
    {
        $this->registerProvidersIn($environment);

        $this->assertInstanceOf(DatabaseMasterDataDirectory::class, $this->app->make(MasterDataDirectory::class));
    }

    public function test_the_sample_data_has_at_least_two_sites_each_with_at_least_two_equipment(): void
    {
        $directory = SampleMasterData::load(new InMemoryMasterDataDirectory);

        $sites = $directory->listSites();
        $this->assertGreaterThanOrEqual(2, count($sites));

        foreach ($sites as $site) {
            $this->assertGreaterThanOrEqual(2, count($directory->listEquipmentAtSite($site->id)), "Site [{$site->id}] needs at least 2 Equipment.");
        }
    }

    public function test_every_sample_equipments_category_is_listed(): void
    {
        $directory = SampleMasterData::load(new InMemoryMasterDataDirectory);
        $categoryIds = array_map(fn (EquipmentCategory $category): string => $category->id, $directory->listEquipmentCategories());

        foreach ($directory->listSites() as $site) {
            foreach ($directory->listEquipmentAtSite($site->id) as $equipment) {
                $this->assertInstanceOf(Equipment::class, $equipment);
                $this->assertContains($equipment->categoryId, $categoryIds, "Equipment [{$equipment->id}] has an unlisted category.");
            }
        }
    }

    public function test_the_sample_requestor_has_a_full_four_level_immediate_head_chain(): void
    {
        $chain = new ImmediateHeadChain(SampleMasterData::load(new InMemoryMasterDataDirectory));

        $headIds = [];
        foreach ([1, 2, 3, 4] as $level) {
            $head = $chain->headAtLevel(SampleMasterData::REQUESTOR_EMPLOYEE_ID, $level);
            $this->assertNotNull($head, "Level {$level} must resolve.");
            $headIds[] = $head->id;
        }

        $this->assertCount(4, array_unique($headIds), 'Each level must be a different employee.');
        $this->assertNotContains(SampleMasterData::REQUESTOR_EMPLOYEE_ID, $headIds);
        $this->assertNull($chain->headAtLevel(SampleMasterData::REQUESTOR_EMPLOYEE_ID, 5));
    }

    public function test_the_sample_data_has_no_building_category_or_equipment(): void
    {
        $directory = SampleMasterData::load(new InMemoryMasterDataDirectory);

        $this->assertSame(['GENSET', 'ACU', 'ATS'], array_map(fn (EquipmentCategory $category): string => $category->id, $directory->listEquipmentCategories()));
    }
}
