<?php

namespace Database\Seeders;

use App\MasterData\Data\Employee;
use App\MasterData\Data\Equipment;
use App\MasterData\Data\Site;
use App\MasterData\InMemoryMasterDataDirectory;
use App\MasterData\SampleMasterData;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Writes SampleMasterData into the PSS master data tables, so local dev and demos work without the source database
 * (issue 03). Upserts by source ID, so it is safe to run again. A later real import deactivates these sample rows.
 */
class MasterDataSampleSeeder extends Seeder
{
    public function run(): void
    {
        $sample = SampleMasterData::load(new InMemoryMasterDataDirectory);
        $now = now();

        foreach ($sample->listEquipmentCategories() as $category) {
            DB::table('equipment_categories')->updateOrInsert(
                ['code' => $category->id],
                ['name' => $category->name, 'created_at' => $now, 'updated_at' => $now],
            );
        }

        foreach ($sample->listSites() as $site) {
            $this->upsert('sites', $this->siteRow($site));

            foreach ($sample->listEquipmentAtSite($site->id) as $equipment) {
                $this->upsert('equipment', $this->equipmentRow($equipment));
            }
        }

        foreach (SampleMasterData::EMPLOYEE_IDS as $employeeId) {
            $this->upsert('employees', $this->employeeRow($sample->findEmployee($employeeId)));
        }
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function upsert(string $table, array $row): void
    {
        $now = now();
        DB::table($table)->updateOrInsert(
            ['source_id' => $row['source_id']],
            [...$row, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function siteRow(Site $site): array
    {
        return ['source_id' => $site->id, 'name' => $site->name];
    }

    /**
     * @return array<string, mixed>
     */
    private function equipmentRow(Equipment $equipment): array
    {
        return [
            'source_id' => $equipment->id,
            'equipment_category_code' => $equipment->categoryId,
            'site_source_id' => $equipment->siteId,
            'name' => $equipment->name,
            'location' => $equipment->location,
            'installed_on' => $equipment->installedOn?->format('Y-m-d'),
            'operating_status' => $equipment->operatingStatus,
            'details' => json_encode([]),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function employeeRow(Employee $employee): array
    {
        return [
            'source_id' => $employee->id,
            'name' => $employee->name,
            'position_title' => $employee->positionTitle,
            'immediate_head_id' => $employee->immediateHeadId,
        ];
    }
}
