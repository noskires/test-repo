<?php

namespace App\Checklists;

use App\MasterData\Data\EquipmentCategory;
use App\MasterData\MasterDataDirectory;
use App\Models\ChecklistTemplate;
use Illuminate\Support\Facades\DB;

/**
 * Loads checklist definitions into versioned templates. Idempotent and all-or-nothing:
 * an unchanged definition writes nothing, a changed one adds version N+1, and any invalid definition
 * rejects the whole load without writing.
 */
final class ChecklistLoader
{
    public function __construct(private readonly MasterDataDirectory $directory) {}

    /**
     * @param  list<array<string, mixed>>  $rawDefinitions
     * @return list<array{key: string, version_number: int, created: bool}>
     *
     * @throws InvalidChecklistDefinition
     */
    public function load(array $rawDefinitions): array
    {
        $definitions = array_map(fn (array $raw): ChecklistDefinition => ChecklistDefinition::fromArray($raw), $rawDefinitions);

        $this->assertLoadIsConsistent($definitions);

        return DB::transaction(fn (): array => array_map(
            fn (ChecklistDefinition $definition): array => $this->loadOne($definition),
            $definitions,
        ));
    }

    /**
     * @param  list<ChecklistDefinition>  $definitions
     */
    private function assertLoadIsConsistent(array $definitions): void
    {
        $knownCategoryIds = array_map(
            fn (EquipmentCategory $category): string => $category->id,
            $this->directory->listEquipmentCategories(),
        );
        $seenKeys = [];
        $seenCategoryIds = [];

        foreach ($definitions as $definition) {
            if (! in_array($definition->equipmentCategoryId, $knownCategoryIds, true)) {
                throw InvalidChecklistDefinition::because($definition->key, "Equipment Category [{$definition->equipmentCategoryId}] is unknown to the directory.");
            }

            if (isset($seenKeys[$definition->key])) {
                throw InvalidChecklistDefinition::because($definition->key, 'the key appears more than once in this load.');
            }

            if (isset($seenCategoryIds[$definition->equipmentCategoryId])) {
                throw InvalidChecklistDefinition::because($definition->key, "Equipment Category [{$definition->equipmentCategoryId}] already has a checklist [{$seenCategoryIds[$definition->equipmentCategoryId]}] in this load.");
            }

            $seenKeys[$definition->key] = true;
            $seenCategoryIds[$definition->equipmentCategoryId] = $definition->key;
        }
    }

    /**
     * @return array{key: string, version_number: int, created: bool}
     */
    private function loadOne(ChecklistDefinition $definition): array
    {
        $template = ChecklistTemplate::query()->lockForUpdate()->firstWhere('key', $definition->key);

        if ($template === null) {
            $this->assertCategoryIsFree($definition);
            $template = ChecklistTemplate::create([
                'key' => $definition->key,
                'name' => $definition->name,
                'equipment_category_id' => $definition->equipmentCategoryId,
            ]);
        } elseif ($template->equipment_category_id !== $definition->equipmentCategoryId) {
            throw InvalidChecklistDefinition::because($definition->key, "it belongs to Equipment Category [{$template->equipment_category_id}] and cannot move to [{$definition->equipmentCategoryId}]. Use a new key.");
        } elseif ($template->name !== $definition->name) {
            $template->update(['name' => $definition->name]);
        }

        $currentVersion = $template->currentVersion()->first();

        if ($currentVersion?->content_fingerprint === $definition->fingerprint()) {
            return ['key' => $definition->key, 'version_number' => $currentVersion->version_number, 'created' => false];
        }

        $version = $template->versions()->create([
            'version_number' => ($currentVersion->version_number ?? 0) + 1,
            'content_fingerprint' => $definition->fingerprint(),
        ]);

        foreach ($definition->items as $index => $item) {
            $version->items()->create(['position' => $index + 1, ...$item->toArray()]);
        }

        return ['key' => $definition->key, 'version_number' => $version->version_number, 'created' => true];
    }

    private function assertCategoryIsFree(ChecklistDefinition $definition): void
    {
        $owner = ChecklistTemplate::query()
            ->where('equipment_category_id', $definition->equipmentCategoryId)
            ->where('is_active', true)
            ->value('key');

        if ($owner !== null) {
            throw InvalidChecklistDefinition::because($definition->key, "Equipment Category [{$definition->equipmentCategoryId}] already has the active checklist [{$owner}].");
        }
    }
}
