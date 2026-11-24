<?php

namespace App\MasterData\Import;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

/**
 * Copies the source database's master data into the PSS tables (issue 03). Upserts by source ID, marks rows that
 * left the source inactive (never deletes) and reactivates them when they come back. A second run with the same
 * data changes nothing.
 *
 * Every source table is read before anything is written, so a failing read writes nothing. The writes run in one
 * transaction on the PSS connection.
 */
final class MasterDataImporter
{
    private const int INSERT_CHUNK = 500;

    /**
     * @return array<string, array{inserted: int, updated: int, deactivated: int}> per PSS table
     */
    public function import(ConnectionInterface $source): array
    {
        $sites = $this->read($source, SourceSchema::SITES_TABLE, SourceSchema::siteColumns(), SourceSchema::siteRow(...));
        $employees = $this->read($source, SourceSchema::EMPLOYEES_TABLE, SourceSchema::employeeColumns(), SourceSchema::employeeRow(...));

        $equipment = [];
        foreach (SourceSchema::EQUIPMENT_TABLES as $categoryCode => $table) {
            $equipment += $this->read(
                $source,
                $table,
                SourceSchema::equipmentColumns($categoryCode),
                fn (object $row): array => SourceSchema::equipmentRow($categoryCode, $row),
            );
        }

        return DB::transaction(fn (): array => [
            'equipment_categories' => $this->syncCategories(),
            'sites' => $this->sync('sites', $sites),
            'equipment' => $this->sync('equipment', $equipment, jsonColumns: ['details']),
            'employees' => $this->sync('employees', $employees),
        ]);
    }

    /**
     * @param  list<string>  $columns
     * @param  callable(object): array<string, mixed>  $map
     * @return array<string, array<string, mixed>> mapped rows keyed by source ID
     */
    private function read(ConnectionInterface $source, string $table, array $columns, callable $map): array
    {
        $rows = [];
        foreach ($source->table($table)->select($columns)->orderBy('id')->cursor() as $sourceRow) {
            $row = $map($sourceRow);
            $rows[$row['source_id']] = $row;
        }

        return $rows;
    }

    /**
     * @param  array<string, array<string, mixed>>  $sourceRows  keyed by source ID
     * @param  list<string>  $jsonColumns
     * @return array{inserted: int, updated: int, deactivated: int}
     */
    private function sync(string $table, array $sourceRows, array $jsonColumns = []): array
    {
        $counts = ['inserted' => 0, 'updated' => 0, 'deactivated' => 0];
        $now = now();
        $existing = DB::table($table)->get()->keyBy('source_id');
        $inserts = [];

        foreach ($sourceRows as $sourceId => $row) {
            $current = $existing->get($sourceId);

            if ($current === null) {
                $inserts[] = [...$this->encode($row, $jsonColumns), 'is_active' => true, 'created_at' => $now, 'updated_at' => $now];
                $counts['inserted']++;

                continue;
            }

            if ($this->differs($current, $row, $jsonColumns) || ! (bool) $current->is_active) {
                DB::table($table)->where('id', $current->id)->update(
                    [...$this->encode($row, $jsonColumns), 'is_active' => true, 'updated_at' => $now],
                );
                $counts['updated']++;
            }
        }

        foreach (array_chunk($inserts, self::INSERT_CHUNK) as $chunk) {
            DB::table($table)->insert($chunk);
        }

        $goneIds = $existing
            ->filter(fn (object $current): bool => (bool) $current->is_active && ! array_key_exists($current->source_id, $sourceRows))
            ->pluck('id');

        foreach ($goneIds->chunk(self::INSERT_CHUNK) as $chunk) {
            DB::table($table)->whereIn('id', $chunk->all())->update(['is_active' => false, 'updated_at' => $now]);
        }
        $counts['deactivated'] = $goneIds->count();

        return $counts;
    }

    /**
     * The categories are fixed in SourceSchema; they are never deactivated.
     *
     * @return array{inserted: int, updated: int, deactivated: int}
     */
    private function syncCategories(): array
    {
        $counts = ['inserted' => 0, 'updated' => 0, 'deactivated' => 0];
        $now = now();
        $existing = DB::table('equipment_categories')->get()->keyBy('code');

        foreach (SourceSchema::equipmentCategories() as $category) {
            $current = $existing->get($category['code']);

            if ($current === null) {
                DB::table('equipment_categories')->insert([...$category, 'created_at' => $now, 'updated_at' => $now]);
                $counts['inserted']++;
            } elseif ($current->name !== $category['name']) {
                DB::table('equipment_categories')->where('id', $current->id)->update(['name' => $category['name'], 'updated_at' => $now]);
                $counts['updated']++;
            }
        }

        return $counts;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<string>  $jsonColumns
     */
    private function differs(object $current, array $row, array $jsonColumns): bool
    {
        foreach ($row as $column => $value) {
            $currentValue = $current->{$column};

            if (in_array($column, $jsonColumns, true)) {
                /** MySQL may reorder JSON keys, so compare decoded and key-order independent. */
                if ($this->sortedKeys($currentValue === null ? null : json_decode($currentValue, true)) !== $this->sortedKeys($value)) {
                    return true;
                }

                continue;
            }

            if ($currentValue !== null && $value !== null) {
                $currentValue = (string) $currentValue;
                $value = (string) $value;
            }

            if ($currentValue !== $value) {
                return true;
            }
        }

        return false;
    }

    private function sortedKeys(mixed $value): mixed
    {
        if (is_array($value)) {
            ksort($value);
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<string>  $jsonColumns
     * @return array<string, mixed>
     */
    private function encode(array $row, array $jsonColumns): array
    {
        foreach ($jsonColumns as $column) {
            $row[$column] = $row[$column] === null ? null : json_encode($row[$column]);
        }

        return $row;
    }
}
