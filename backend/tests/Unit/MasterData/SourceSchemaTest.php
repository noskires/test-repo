<?php

namespace Tests\Unit\MasterData;

use App\MasterData\Import\SourceSchema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SourceSchemaTest extends TestCase
{
    public function test_an_aircondition_unit_row_becomes_an_acu_equipment(): void
    {
        $row = (object) [
            'id' => 17, 'site_id' => 3, 'name' => 'ACU 3F East', 'location' => '3rd Floor', 'installed_on' => '2023-07-01 00:00:00',
            'status' => 'Operational', 'brand' => 'Carrier', 'model' => 'X1', 'capacity_tons' => 5, 'refrigerant' => 'R410A',
        ];

        $this->assertSame([
            'source_id' => 'ACU-17',
            'equipment_category_code' => 'ACU',
            'site_source_id' => '3',
            'name' => 'ACU 3F East',
            'location' => '3rd Floor',
            'installed_on' => '2023-07-01',
            'operating_status' => 'operational',
            'details' => ['brand' => 'Carrier', 'model' => 'X1', 'capacity_tons' => 5, 'refrigerant' => 'R410A'],
        ], SourceSchema::equipmentRow('ACU', $row));
    }

    public function test_a_genset_row_becomes_a_genset_equipment(): void
    {
        $row = (object) [
            'id' => 17, 'site_id' => 3, 'name' => 'Genset 1', 'location' => '', 'installed_on' => null,
            'status' => 'under repair', 'brand' => 'Cummins', 'model' => 'C500', 'capacity_kva' => 500, 'fuel_type' => 'diesel',
        ];

        $this->assertSame([
            'source_id' => 'GENSET-17',
            'equipment_category_code' => 'GENSET',
            'site_source_id' => '3',
            'name' => 'Genset 1',
            'location' => null,
            'installed_on' => null,
            'operating_status' => 'non-operational',
            'details' => ['brand' => 'Cummins', 'model' => 'C500', 'capacity_kva' => 500, 'fuel_type' => 'diesel'],
        ], SourceSchema::equipmentRow('GENSET', $row));
    }

    public function test_an_ats_row_becomes_an_ats_equipment(): void
    {
        $row = (object) [
            'id' => 4, 'site_id' => 'CEB', 'name' => 'Cebu ATS', 'location' => 'Electrical Room', 'installed_on' => '2021-11-20',
            'status' => null, 'brand' => 'ABB', 'model' => 'TruONE', 'rating_amperes' => 800,
        ];

        $this->assertSame([
            'source_id' => 'ATS-4',
            'equipment_category_code' => 'ATS',
            'site_source_id' => 'CEB',
            'name' => 'Cebu ATS',
            'location' => 'Electrical Room',
            'installed_on' => '2021-11-20',
            'operating_status' => null,
            'details' => ['brand' => 'ABB', 'model' => 'TruONE', 'rating_amperes' => 800],
        ], SourceSchema::equipmentRow('ATS', $row));
    }

    public function test_a_site_row_becomes_a_site(): void
    {
        $this->assertSame(
            ['source_id' => '3', 'name' => 'Makati Office'],
            SourceSchema::siteRow((object) ['id' => 3, 'name' => 'Makati Office']),
        );
    }

    public function test_an_employee_row_keeps_the_immediate_head_and_position_title(): void
    {
        $this->assertSame(
            ['source_id' => '1001', 'name' => 'Rina Requestor', 'position_title' => 'Facilities Coordinator', 'immediate_head_id' => '2001'],
            SourceSchema::employeeRow((object) ['id' => 1001, 'name' => 'Rina Requestor', 'position_title' => 'Facilities Coordinator', 'immediate_head_id' => 2001]),
        );
    }

    public function test_an_employee_at_the_top_of_the_chain_has_no_immediate_head(): void
    {
        $row = SourceSchema::employeeRow((object) ['id' => 5001, 'name' => 'Sofia Sector Head', 'position_title' => '', 'immediate_head_id' => null]);

        $this->assertNull($row['immediate_head_id']);
        $this->assertNull($row['position_title']);
    }

    /**
     * @return array<string, array{mixed, ?string}>
     */
    public static function sourceStatuses(): array
    {
        return [
            'operational' => ['operational', 'operational'],
            'operational, any case and padding' => ['  OPERATIONAL ', 'operational'],
            'empty is unknown' => ['', null],
            'null is unknown' => [null, null],
            'non-operational' => ['non-operational', 'non-operational'],
            'anything else' => ['decommissioned', 'non-operational'],
        ];
    }

    #[DataProvider('sourceStatuses')]
    public function test_the_operating_status_is_mapped(mixed $sourceStatus, ?string $expected): void
    {
        $this->assertSame($expected, SourceSchema::operatingStatus($sourceStatus));
    }

    public function test_the_categories_are_acu_genset_and_ats_with_stable_codes_and_no_building(): void
    {
        $this->assertSame(
            [['code' => 'ACU', 'name' => 'ACU'], ['code' => 'GENSET', 'name' => 'Genset'], ['code' => 'ATS', 'name' => 'ATS']],
            SourceSchema::equipmentCategories(),
        );
        $this->assertSame(['ACU' => 'aircondition_units', 'GENSET' => 'gensets', 'ATS' => 'ats'], SourceSchema::EQUIPMENT_TABLES);
    }
}
