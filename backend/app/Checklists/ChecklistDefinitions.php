<?php

namespace App\Checklists;

/**
 * The single place the fixed set of PM Checklists is defined, loaded by `php artisan checklists:load`.
 *
 * Empty until issue 31 supplies the approved Genset, ACU, ATS and Building content and their external
 * Equipment Category IDs. Editing a definition here and re-running the loader creates the next version.
 */
class ChecklistDefinitions
{
    /**
     * @return list<array{key: string, name: string, equipment_category_id: string, items: list<array<string, mixed>>}>
     */
    public function all(): array
    {
        return [];
    }
}
