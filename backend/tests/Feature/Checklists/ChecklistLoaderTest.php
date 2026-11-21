<?php

namespace Tests\Feature\Checklists;

use App\Checklists\ChecklistDefinitions;
use App\Checklists\ChecklistLoader;
use App\Checklists\InvalidChecklistDefinition;
use App\MasterData\Data\EquipmentCategory;
use App\MasterData\InMemoryMasterDataDirectory;
use App\MasterData\MasterDataDirectory;
use App\Models\ChecklistItem;
use App\Models\ChecklistTemplate;
use App\Models\ChecklistTemplateVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Unit\Checklists\ChecklistDefinitionTest;

class ChecklistLoaderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var InMemoryMasterDataDirectory $directory */
        $directory = $this->app->make(MasterDataDirectory::class);
        $directory
            ->addEquipmentCategory(new EquipmentCategory(id: 'CAT-GENSET', name: 'Genset'))
            ->addEquipmentCategory(new EquipmentCategory(id: 'CAT-ACU', name: 'ACU'));
    }

    private function loader(): ChecklistLoader
    {
        return $this->app->make(ChecklistLoader::class);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function gensetDefinition(array $overrides = []): array
    {
        return ChecklistDefinitionTest::validDefinition($overrides);
    }

    /**
     * @param  list<array<string, mixed>>  $definitions
     */
    private function useShippedDefinitions(array $definitions): void
    {
        $this->app->instance(ChecklistDefinitions::class, new class($definitions) extends ChecklistDefinitions
        {
            /**
             * @param  list<array<string, mixed>>  $definitions
             */
            public function __construct(private readonly array $definitions) {}

            public function all(): array
            {
                return $this->definitions;
            }
        });
    }

    private function assertNothingWritten(): void
    {
        $this->assertDatabaseCount('checklist_templates', 0);
        $this->assertDatabaseCount('checklist_template_versions', 0);
        $this->assertDatabaseCount('checklist_items', 0);
    }

    /**
     * @return list<string>
     */
    private function itemLabels(ChecklistTemplateVersion $version): array
    {
        return $version->items()->orderBy('position')->pluck('label')->all();
    }

    public function test_loading_a_definition_creates_version_1_with_its_items_in_order(): void
    {
        $this->loader()->load([$this->gensetDefinition()]);

        $template = ChecklistTemplate::query()->sole();
        $this->assertSame('genset', $template->key);
        $this->assertSame('Genset PM Checklist', $template->name);
        $this->assertSame('CAT-GENSET', $template->equipment_category_id);

        $version = $template->versions()->sole();
        $this->assertSame(1, $version->version_number);
        $this->assertSame(['Engine oil inspection', 'Battery voltage', 'Photo of control panel'], $this->itemLabels($version));
        $this->assertSame([1, 2, 3], $version->items()->orderBy('position')->pluck('position')->all());

        $battery = $version->items()->where('label', 'Battery voltage')->sole();
        $this->assertSame('numeric', $battery->response_type->value);
        $this->assertSame('12', $battery->acceptable_min);
        $this->assertSame('13.5', $battery->acceptable_max);
    }

    public function test_loading_the_same_definition_again_creates_no_new_version(): void
    {
        $this->loader()->load([$this->gensetDefinition()]);
        $itemIds = ChecklistItem::query()->orderBy('id')->pluck('id')->all();

        $result = $this->loader()->load([$this->gensetDefinition()]);

        $this->assertDatabaseCount('checklist_template_versions', 1);
        $this->assertSame($itemIds, ChecklistItem::query()->orderBy('id')->pluck('id')->all());
        $this->assertSame([['key' => 'genset', 'version_number' => 1, 'created' => false]], $result);
    }

    public function test_renaming_a_checklist_updates_its_name_without_a_new_version(): void
    {
        $this->loader()->load([$this->gensetDefinition()]);
        $this->loader()->load([$this->gensetDefinition(['name' => 'Generator Set Checklist'])]);

        $this->assertSame('Generator Set Checklist', ChecklistTemplate::query()->sole()->name);
        $this->assertDatabaseCount('checklist_template_versions', 1);
    }

    public function test_loading_a_changed_definition_creates_version_2_and_leaves_version_1_untouched(): void
    {
        $this->loader()->load([$this->gensetDefinition()]);
        $versionOneItems = ChecklistItem::query()->orderBy('id')->get()->toArray();

        $changed = $this->gensetDefinition();
        $changed['items'][0]['label'] = 'Engine oil level and colour';
        $changed['items'][] = ['label' => 'Coolant level', 'response_type' => 'pass_fail', 'mandatory' => true];
        $result = $this->loader()->load([$changed]);

        $template = ChecklistTemplate::query()->sole();
        $this->assertSame([1, 2], $template->versions()->orderBy('version_number')->pluck('version_number')->all());
        $this->assertSame(2, $template->currentVersion->version_number);
        $this->assertSame([['key' => 'genset', 'version_number' => 2, 'created' => true]], $result);

        $versionOne = $template->versions()->where('version_number', 1)->sole();
        $this->assertSame($versionOneItems, $versionOne->items()->orderBy('id')->get()->toArray());
        $this->assertSame(
            ['Engine oil level and colour', 'Battery voltage', 'Photo of control panel', 'Coolant level'],
            $this->itemLabels($template->currentVersion),
        );
    }

    public function test_an_item_removed_from_the_definition_is_absent_from_version_2_but_present_in_version_1(): void
    {
        $this->loader()->load([$this->gensetDefinition()]);

        $changed = $this->gensetDefinition();
        unset($changed['items'][1]);
        $changed['items'] = array_values($changed['items']);
        $this->loader()->load([$changed]);

        $template = ChecklistTemplate::query()->sole();
        $this->assertNotContains('Battery voltage', $this->itemLabels($template->currentVersion));
        $this->assertContains('Battery voltage', $this->itemLabels($template->versions()->where('version_number', 1)->sole()));
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function rejectedDefinitions(): array
    {
        return [
            'unknown category' => [ChecklistDefinitionTest::validDefinition(['equipment_category_id' => 'CAT-UNKNOWN'])],
            'unknown response type' => [ChecklistDefinitionTest::validDefinition(['items' => [['label' => 'Check', 'response_type' => 'thumbs_up']]])],
            'numeric min above max' => [ChecklistDefinitionTest::validDefinition(['items' => [['label' => 'Volts', 'response_type' => 'numeric', 'acceptable_min' => 14, 'acceptable_max' => 12]]])],
        ];
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    #[DataProvider('rejectedDefinitions')]
    public function test_an_invalid_definition_is_rejected_and_writes_nothing(array $definition): void
    {
        try {
            $this->loader()->load([$definition]);
            $this->fail('The definition should have been rejected.');
        } catch (InvalidChecklistDefinition $exception) {
            $this->assertStringContainsString('genset', $exception->getMessage());
        }

        $this->assertNothingWritten();
    }

    public function test_one_invalid_definition_rejects_the_whole_load(): void
    {
        try {
            $this->loader()->load([
                ChecklistDefinitionTest::validDefinition(['key' => 'acu', 'equipment_category_id' => 'CAT-ACU']),
                $this->gensetDefinition(['equipment_category_id' => 'CAT-UNKNOWN']),
            ]);
            $this->fail('The load should have been rejected.');
        } catch (InvalidChecklistDefinition) {
            $this->assertNothingWritten();
        }
    }

    public function test_two_definitions_for_the_same_category_in_one_load_are_rejected(): void
    {
        try {
            $this->loader()->load([
                $this->gensetDefinition(),
                $this->gensetDefinition(['key' => 'genset-alt']),
            ]);
            $this->fail('Two definitions for CAT-GENSET should have been rejected.');
        } catch (InvalidChecklistDefinition $exception) {
            $this->assertStringContainsString('CAT-GENSET', $exception->getMessage());
        }

        $this->assertNothingWritten();
    }

    public function test_a_definition_for_a_category_that_already_has_an_active_template_is_rejected(): void
    {
        $this->loader()->load([$this->gensetDefinition()]);

        try {
            $this->loader()->load([$this->gensetDefinition(['key' => 'genset-alt', 'name' => 'Another genset list'])]);
            $this->fail('The second template for CAT-GENSET should have been rejected.');
        } catch (InvalidChecklistDefinition $exception) {
            $this->assertStringContainsString('CAT-GENSET', $exception->getMessage());
        }

        $this->assertDatabaseCount('checklist_templates', 1);
        $this->assertDatabaseCount('checklist_template_versions', 1);
    }

    public function test_a_template_cannot_move_to_another_category(): void
    {
        $this->loader()->load([$this->gensetDefinition()]);

        $this->expectException(InvalidChecklistDefinition::class);

        $this->loader()->load([$this->gensetDefinition(['equipment_category_id' => 'CAT-ACU'])]);
    }

    public function test_written_versions_and_items_cannot_be_updated(): void
    {
        $this->loader()->load([$this->gensetDefinition()]);

        $item = ChecklistItem::query()->firstOrFail();
        try {
            $item->update(['label' => 'Tampered']);
            $this->fail('Checklist items must be immutable.');
        } catch (LogicException) {
            $this->assertNotSame('Tampered', $item->fresh()->label);
        }

        $this->expectException(LogicException::class);
        ChecklistTemplateVersion::query()->firstOrFail()->update(['version_number' => 9]);
    }

    public function test_the_artisan_command_loads_the_shipped_definitions_idempotently(): void
    {
        $this->useShippedDefinitions([$this->gensetDefinition()]);

        $this->artisan('checklists:load')->expectsOutputToContain('genset')->assertSuccessful();
        $this->artisan('checklists:load')->assertSuccessful();

        $this->assertDatabaseCount('checklist_template_versions', 1);
    }

    public function test_the_artisan_command_fails_on_an_invalid_definition(): void
    {
        $this->useShippedDefinitions([$this->gensetDefinition(['equipment_category_id' => 'CAT-UNKNOWN'])]);

        $this->artisan('checklists:load')->assertFailed();

        $this->assertNothingWritten();
    }

    public function test_the_shipped_definitions_are_empty_until_issue_31(): void
    {
        $this->assertSame([], (new ChecklistDefinitions)->all());

        $this->artisan('checklists:load')->assertSuccessful();

        $this->assertNothingWritten();
    }
}
