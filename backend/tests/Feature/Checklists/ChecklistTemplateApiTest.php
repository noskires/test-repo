<?php

namespace Tests\Feature\Checklists;

use App\Checklists\ChecklistLoader;
use App\MasterData\Data\EquipmentCategory;
use App\MasterData\InMemoryMasterDataDirectory;
use App\MasterData\MasterDataDirectory;
use App\Models\ChecklistTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Unit\Checklists\ChecklistDefinitionTest;

class ChecklistTemplateApiTest extends TestCase
{
    use RefreshDatabase;

    private ChecklistTemplate $genset;

    /**
     * Seeds a Genset template with versions 1 and 2, and an ACU template with version 1.
     */
    protected function setUp(): void
    {
        parent::setUp();

        /** @var InMemoryMasterDataDirectory $directory */
        $directory = $this->app->make(MasterDataDirectory::class);
        $directory
            ->addEquipmentCategory(new EquipmentCategory(id: 'CAT-GENSET', name: 'Genset'))
            ->addEquipmentCategory(new EquipmentCategory(id: 'CAT-ACU', name: 'ACU'))
            ->addEquipmentCategory(new EquipmentCategory(id: 'CAT-ATS', name: 'ATS'));

        $loader = $this->app->make(ChecklistLoader::class);
        $acu = ChecklistDefinitionTest::validDefinition([
            'key' => 'acu',
            'name' => 'ACU PM Checklist',
            'equipment_category_id' => 'CAT-ACU',
            'items' => [['label' => 'Filter cleaning', 'response_type' => 'yes_no', 'mandatory' => true]],
        ]);
        $loader->load([ChecklistDefinitionTest::validDefinition(), $acu]);

        $gensetVersionTwo = ChecklistDefinitionTest::validDefinition([
            'items' => [['label' => 'Coolant level', 'response_type' => 'pass_fail', 'acceptable_criteria' => 'Between MIN and MAX marks']],
        ]);
        $loader->load([$gensetVersionTwo, $acu]);

        $this->genset = ChecklistTemplate::query()->where('key', 'genset')->sole();
    }

    public function test_listing_templates_shows_each_with_its_current_version_number(): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson('/api/checklist-templates')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment([
                'id' => $this->genset->id,
                'key' => 'genset',
                'name' => 'Genset PM Checklist',
                'equipment_category_id' => 'CAT-GENSET',
                'current_version_number' => 2,
            ])
            ->assertJsonFragment(['key' => 'acu', 'current_version_number' => 1]);
    }

    public function test_filtering_by_category_returns_the_current_version_with_its_items(): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson('/api/checklist-templates?equipment_category_id=CAT-GENSET')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.key', 'genset')
            ->assertJsonPath('data.0.current_version.version_number', 2)
            ->assertJsonCount(1, 'data.0.current_version.items')
            ->assertJsonPath('data.0.current_version.items.0.label', 'Coolant level')
            ->assertJsonPath('data.0.current_version.items.0.response_type', 'pass_fail')
            ->assertJsonPath('data.0.current_version.items.0.acceptable_criteria', 'Between MIN and MAX marks');
    }

    public function test_filtering_by_a_category_without_a_template_returns_an_empty_result(): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson('/api/checklist-templates?equipment_category_id=CAT-ATS')
            ->assertOk()
            ->assertExactJson(['data' => []]);
    }

    public function test_showing_a_template_returns_its_current_version_and_items(): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson("/api/checklist-templates/{$this->genset->id}")
            ->assertOk()
            ->assertJsonPath('data.key', 'genset')
            ->assertJsonPath('data.current_version_number', 2)
            ->assertJsonPath('data.current_version.version_number', 2)
            ->assertJsonPath('data.current_version.items.0.label', 'Coolant level');
    }

    public function test_an_old_version_is_still_readable_with_its_own_items(): void
    {
        $versionOneItem = $this->genset->versions()->where('version_number', 1)->sole()->items()->orderBy('position')->first();

        $this->actingAs(User::factory()->create())
            ->getJson("/api/checklist-templates/{$this->genset->id}/versions/1")
            ->assertOk()
            ->assertJsonPath('data.version_number', 1)
            ->assertJsonPath('data.checklist_template_id', $this->genset->id)
            ->assertJsonCount(3, 'data.items')
            ->assertJsonPath('data.items.0', [
                'id' => $versionOneItem->id,
                'position' => 1,
                'label' => 'Engine oil inspection',
                'response_type' => 'good_defective',
                'mandatory' => true,
                'remarks_required_on_fail' => true,
                'photo_required' => false,
                'acceptable_min' => null,
                'acceptable_max' => null,
                'acceptable_criteria' => null,
            ])
            ->assertJsonPath('data.items.1.acceptable_min', '12')
            ->assertJsonPath('data.items.1.acceptable_max', '13.5');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unknownResources(): array
    {
        return [
            'unknown template' => ['/api/checklist-templates/9999'],
            'unknown template versions' => ['/api/checklist-templates/9999/versions/1'],
            'unknown version' => ['/api/checklist-templates/{genset}/versions/3'],
            'non-numeric version' => ['/api/checklist-templates/{genset}/versions/latest'],
        ];
    }

    #[DataProvider('unknownResources')]
    public function test_unknown_templates_and_versions_return_404(string $uri): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson(str_replace('{genset}', (string) $this->genset->id, $uri))
            ->assertNotFound();
    }

    public function test_a_version_of_another_template_is_not_found_through_this_template(): void
    {
        $acu = ChecklistTemplate::query()->where('key', 'acu')->sole();

        $this->actingAs(User::factory()->create())
            ->getJson("/api/checklist-templates/{$acu->id}/versions/2")
            ->assertNotFound();
    }

    public function test_every_read_endpoint_returns_401_when_unauthenticated(): void
    {
        $this->getJson('/api/checklist-templates')->assertUnauthorized();
        $this->getJson('/api/checklist-templates?equipment_category_id=CAT-GENSET')->assertUnauthorized();
        $this->getJson("/api/checklist-templates/{$this->genset->id}")->assertUnauthorized();
        $this->getJson("/api/checklist-templates/{$this->genset->id}/versions/1")->assertUnauthorized();
    }

    public function test_there_are_no_write_routes_for_checklist_templates(): void
    {
        $writeRoutes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn (RoutingRoute $route): bool => str_starts_with($route->uri(), 'api/checklist-templates'))
            ->filter(fn (RoutingRoute $route): bool => array_intersect($route->methods(), ['POST', 'PUT', 'PATCH', 'DELETE']) !== []);

        $this->assertCount(0, $writeRoutes);
        $this->actingAs(User::factory()->create())
            ->postJson('/api/checklist-templates', [])
            ->assertMethodNotAllowed();
    }
}
