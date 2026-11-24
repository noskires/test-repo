<?php

namespace App\MasterData\Import;

/**
 * Every source database table and column name, and how a source row becomes a PSS master data row (issue 03).
 *
 * The source schema is GUESSED until the real one is shared. When it is, only this class should change.
 * Building is left out on purpose: no Building table is read and there is no Building category.
 */
final class SourceSchema
{
    public const string SITES_TABLE = 'sites';

    public const string EMPLOYEES_TABLE = 'employees';

    /** Equipment Category code => the source table its Equipment is read from. */
    public const array EQUIPMENT_TABLES = [
        'ACU' => 'aircondition_units',
        'GENSET' => 'gensets',
        'ATS' => 'ats',
    ];

    /** Equipment Category code => display name. The codes are stable: checklists bind to them (issue 31). */
    public const array EQUIPMENT_CATEGORY_NAMES = [
        'ACU' => 'ACU',
        'GENSET' => 'Genset',
        'ATS' => 'ATS',
    ];

    /** Columns every equipment table shares. */
    private const array SHARED_EQUIPMENT_COLUMNS = ['id', 'site_id', 'name', 'location', 'installed_on', 'status'];

    /** Type-specific columns per Equipment Category code. They go into the details JSON as they are. */
    private const array EQUIPMENT_DETAIL_COLUMNS = [
        'ACU' => ['brand', 'model', 'capacity_tons', 'refrigerant'],
        'GENSET' => ['brand', 'model', 'capacity_kva', 'fuel_type'],
        'ATS' => ['brand', 'model', 'rating_amperes'],
    ];

    /** The source status value that means operating. Compared trimmed and case-insensitively. */
    private const string OPERATIONAL_STATUS = 'operational';

    /**
     * @return list<array{code: string, name: string}>
     */
    public static function equipmentCategories(): array
    {
        $categories = [];
        foreach (self::EQUIPMENT_CATEGORY_NAMES as $code => $name) {
            $categories[] = ['code' => $code, 'name' => $name];
        }

        return $categories;
    }

    /**
     * @return list<string>
     */
    public static function siteColumns(): array
    {
        return ['id', 'name'];
    }

    /**
     * @return list<string>
     */
    public static function employeeColumns(): array
    {
        return ['id', 'name', 'position_title', 'immediate_head_id'];
    }

    /**
     * @return list<string>
     */
    public static function equipmentColumns(string $categoryCode): array
    {
        return [...self::SHARED_EQUIPMENT_COLUMNS, ...self::EQUIPMENT_DETAIL_COLUMNS[$categoryCode]];
    }

    /**
     * @return array{source_id: string, name: string}
     */
    public static function siteRow(object $row): array
    {
        return [
            'source_id' => (string) $row->id,
            'name' => (string) $row->name,
        ];
    }

    /**
     * @return array{source_id: string, name: string, position_title: ?string, immediate_head_id: ?string}
     */
    public static function employeeRow(object $row): array
    {
        return [
            'source_id' => (string) $row->id,
            'name' => (string) $row->name,
            'position_title' => self::nullableString($row->position_title ?? null),
            'immediate_head_id' => self::nullableString($row->immediate_head_id ?? null),
        ];
    }

    /**
     * The source ID is prefixed with the category code, because each equipment table numbers its rows on its own.
     *
     * @return array{source_id: string, equipment_category_code: string, site_source_id: string, name: string, location: ?string, installed_on: ?string, operating_status: ?string, details: array<string, mixed>}
     */
    public static function equipmentRow(string $categoryCode, object $row): array
    {
        $details = [];
        foreach (self::EQUIPMENT_DETAIL_COLUMNS[$categoryCode] as $column) {
            $details[$column] = $row->{$column} ?? null;
        }

        return [
            'source_id' => self::equipmentSourceId($categoryCode, $row->id),
            'equipment_category_code' => $categoryCode,
            'site_source_id' => (string) $row->site_id,
            'name' => (string) $row->name,
            'location' => self::nullableString($row->location ?? null),
            'installed_on' => self::date($row->installed_on ?? null),
            'operating_status' => self::operatingStatus($row->status ?? null),
            'details' => $details,
        ];
    }

    public static function equipmentSourceId(string $categoryCode, int|string $sourceKey): string
    {
        return $categoryCode.'-'.$sourceKey;
    }

    /**
     * `operational` stays `operational`, empty is unknown (null), anything else is `non-operational`.
     */
    public static function operatingStatus(mixed $sourceStatus): ?string
    {
        $status = strtolower(trim((string) $sourceStatus));

        if ($status === '') {
            return null;
        }

        return $status === self::OPERATIONAL_STATUS ? 'operational' : 'non-operational';
    }

    /**
     * A date or datetime value as Y-m-d, taking only its date part. Empty is null.
     */
    private static function date(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : substr($value, 0, 10);
    }

    private static function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
