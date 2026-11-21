<?php

namespace App\Checklists;

/**
 * A PM Checklist as described in data (see ChecklistDefinitions). Pure and framework-free.
 *
 * The key identifies the checklist across loads. The fingerprint covers only the ordered items,
 * so renaming a checklist does not create a new version.
 */
final readonly class ChecklistDefinition
{
    /**
     * @param  list<ChecklistItemDefinition>  $items
     */
    public function __construct(
        public string $key,
        public string $name,
        public string $equipmentCategoryId,
        public array $items,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $key = $data['key'] ?? null;
        if (! is_string($key) || trim($key) === '') {
            throw InvalidChecklistDefinition::because('(no key)', 'a definition needs a key.');
        }

        foreach (['name', 'equipment_category_id'] as $field) {
            if (! is_string($data[$field] ?? null) || trim($data[$field]) === '') {
                throw InvalidChecklistDefinition::because($key, "[{$field}] is required.");
            }
        }

        $rawItems = $data['items'] ?? null;
        if (! is_array($rawItems) || $rawItems === [] || ! array_is_list($rawItems)) {
            throw InvalidChecklistDefinition::because($key, 'it needs a list of at least one item.');
        }

        $items = [];
        foreach ($rawItems as $index => $rawItem) {
            if (! is_array($rawItem)) {
                throw InvalidChecklistDefinition::because($key, 'item '.($index + 1).' must be an array.');
            }

            $items[] = ChecklistItemDefinition::fromArray($key, $index + 1, $rawItem);
        }

        $labels = array_map(fn (ChecklistItemDefinition $item): string => mb_strtolower($item->label), $items);
        if (count($labels) !== count(array_unique($labels))) {
            throw InvalidChecklistDefinition::because($key, 'item labels must be unique.');
        }

        return new self(trim($key), trim($data['name']), trim($data['equipment_category_id']), $items);
    }

    public function fingerprint(): string
    {
        return hash('sha256', json_encode(
            array_map(fn (ChecklistItemDefinition $item): array => $item->toArray(), $this->items),
            JSON_THROW_ON_ERROR,
        ));
    }
}
