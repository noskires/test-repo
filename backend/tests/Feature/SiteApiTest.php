<?php

namespace Tests\Feature;

use App\MasterData\Data\Equipment;
use App\MasterData\Data\Site;
use App\MasterData\InMemoryMasterDataDirectory;
use App\MasterData\MasterDataDirectory;
use App\Models\User;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SiteApiTest extends TestCase
{
    use RefreshDatabase;

    private function seedDirectory(): void
    {
        $directory = $this->app->make(MasterDataDirectory::class);
        $this->assertInstanceOf(InMemoryMasterDataDirectory::class, $directory);

        $directory
            ->addSite(new Site(id: 'S-1', name: 'Makati Office'))
            ->addSite(new Site(id: 'S-2', name: 'Cebu Site'))
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
            ->addEquipment(new Equipment(id: 'E-3', siteId: 'S-2', name: 'Genset Cebu', categoryId: 'C-1'));
    }

    public function test_the_testing_container_binds_the_port_to_a_shared_in_memory_fake(): void
    {
        $this->assertInstanceOf(InMemoryMasterDataDirectory::class, $this->app->make(MasterDataDirectory::class));
        $this->assertSame($this->app->make(MasterDataDirectory::class), $this->app->make(MasterDataDirectory::class));
    }

    public function test_listing_sites_returns_the_seeded_sites(): void
    {
        $this->seedDirectory();

        $this->actingAs(User::factory()->create())
            ->getJson('/api/sites')
            ->assertOk()
            ->assertExactJson(['data' => [
                ['id' => 'S-1', 'name' => 'Makati Office'],
                ['id' => 'S-2', 'name' => 'Cebu Site'],
            ]]);
    }

    public function test_listing_a_sites_equipment_returns_only_that_sites_equipment(): void
    {
        $this->seedDirectory();

        $this->actingAs(User::factory()->create())
            ->getJson('/api/sites/S-1/equipment')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0', [
                'id' => 'E-1',
                'site_id' => 'S-1',
                'name' => 'Genset 1',
                'category_id' => 'C-1',
                'location' => 'Basement, Genset Room',
                'installed_on' => '2024-01-15',
                'operating_status' => 'operational',
            ])
            ->assertJsonPath('data.1.id', 'E-2')
            ->assertJsonMissing(['id' => 'E-3']);
    }

    public function test_listing_equipment_for_an_unknown_site_returns_404(): void
    {
        $this->seedDirectory();

        $this->actingAs(User::factory()->create())
            ->getJson('/api/sites/S-unknown/equipment')
            ->assertNotFound();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function endpoints(): array
    {
        return [
            'list sites' => ['/api/sites'],
            'list site equipment' => ['/api/sites/S-1/equipment'],
        ];
    }

    #[DataProvider('endpoints')]
    public function test_endpoints_return_401_when_unauthenticated(string $uri): void
    {
        $this->seedDirectory();

        $this->getJson($uri)->assertUnauthorized();
    }

    /**
     * ADR 0001 (amended by issue 03): PSS keeps a read-only copy of the master data. Only the import touches it.
     */
    public function test_only_the_master_data_layer_touches_the_master_data_tables(): void
    {
        $normalisePath = fn (string $path): string => str_replace('\\', '/', $path);
        $masterDataDirectory = $normalisePath(app_path('MasterData')).'/';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php' || str_starts_with($normalisePath($file->getPathname()), $masterDataDirectory)) {
                continue;
            }

            $this->assertDoesNotMatchRegularExpression(
                "/table\\(\\s*'(sites|equipment|equipment_categories|employees)'/",
                file_get_contents($file->getPathname()),
                "{$file->getPathname()} reads or writes a master data table outside App\\MasterData.",
            );
        }
    }

    public function test_nothing_outside_the_adapter_layer_uses_an_http_client(): void
    {
        $normalisePath = fn (string $path): string => str_replace('\\', '/', $path);
        $adapterDirectory = $normalisePath(app_path('MasterData/Adapters')).'/';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php' || str_starts_with($normalisePath($file->getPathname()), $adapterDirectory)) {
                continue;
            }

            $source = file_get_contents($file->getPathname());

            $this->assertDoesNotMatchRegularExpression(
                '/Illuminate\\\\Support\\\\Facades\\\\Http\b|Illuminate\\\\Http\\\\Client\\\\|GuzzleHttp\\\\/',
                $source,
                "{$file->getPathname()} uses an HTTP client outside the master data adapter layer.",
            );
        }
    }
}
