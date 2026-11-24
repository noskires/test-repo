<?php

namespace Tests\Feature;

use App\Console\Commands\ImportMasterData;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * pss:import-master-data (issue 03) against a second in-memory SQLite connection holding the guessed source schema.
 */
class ImportMasterDataCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.connections.master_source' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::purge('master_source');
        $this->createSourceSchema();
    }

    private function source(): Connection
    {
        return DB::connection('master_source');
    }

    private function createSourceSchema(): void
    {
        $schema = $this->source()->getSchemaBuilder();

        $schema->create('sites', function (Blueprint $table) {
            $table->id();
            $table->string('name');
        });
        $schema->create('employees', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('position_title')->nullable();
            $table->unsignedBigInteger('immediate_head_id')->nullable();
        });

        $equipmentTables = [
            'aircondition_units' => ['brand', 'model', 'capacity_tons', 'refrigerant'],
            'gensets' => ['brand', 'model', 'capacity_kva', 'fuel_type'],
            'ats' => ['brand', 'model', 'rating_amperes'],
            'buildings' => ['floors'],
        ];
        foreach ($equipmentTables as $name => $detailColumns) {
            $schema->create($name, function (Blueprint $table) use ($detailColumns) {
                $table->id();
                $table->unsignedBigInteger('site_id');
                $table->string('name');
                $table->string('location')->nullable();
                $table->date('installed_on')->nullable();
                $table->string('status')->nullable();
                foreach ($detailColumns as $column) {
                    $table->string($column)->nullable();
                }
            });
        }
    }

    private function seedSource(): void
    {
        $this->source()->table('sites')->insert([
            ['id' => 1, 'name' => 'Makati Office'],
            ['id' => 2, 'name' => 'Cebu Site'],
        ]);
        $this->source()->table('employees')->insert([
            ['id' => 1001, 'name' => 'Rina Requestor', 'position_title' => 'Facilities Coordinator', 'immediate_head_id' => 2001],
            ['id' => 2001, 'name' => 'Tomas Team Head', 'position_title' => 'Team Head', 'immediate_head_id' => null],
        ]);
        $this->source()->table('aircondition_units')->insert([
            'id' => 1, 'site_id' => 1, 'name' => 'ACU 3F', 'location' => '3rd Floor', 'installed_on' => '2023-07-01',
            'status' => 'operational', 'brand' => 'Carrier', 'model' => 'X1', 'capacity_tons' => '5', 'refrigerant' => 'R410A',
        ]);
        $this->source()->table('gensets')->insert([
            'id' => 1, 'site_id' => 1, 'name' => 'Genset 1', 'location' => 'Basement', 'installed_on' => '2022-03-15',
            'status' => 'operational', 'brand' => 'Cummins', 'model' => 'C500', 'capacity_kva' => '500', 'fuel_type' => 'diesel',
        ]);
        $this->source()->table('ats')->insert([
            'id' => 1, 'site_id' => 2, 'name' => 'Cebu ATS', 'location' => 'Electrical Room', 'installed_on' => '2021-11-20',
            'status' => 'under repair', 'brand' => 'ABB', 'model' => 'TruONE', 'rating_amperes' => '800',
        ]);
        $this->source()->table('buildings')->insert([
            'id' => 1, 'site_id' => 1, 'name' => 'Makati Office Building', 'status' => 'operational', 'floors' => '12',
        ]);
    }

    /**
     * @return array<string, int>
     */
    private function rowCounts(): array
    {
        return collect(['sites', 'equipment', 'equipment_categories', 'employees'])
            ->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->count()])
            ->all();
    }

    public function test_without_the_connection_configured_it_fails_with_a_clear_message_and_writes_nothing(): void
    {
        config(['database.connections.master_source.database' => null]);
        $before = $this->rowCounts();

        $this->artisan('pss:import-master-data')
            ->expectsOutputToContain('The master data source database is not configured')
            ->assertFailed();

        $this->assertSame($before, $this->rowCounts());
        $this->assertSame(0, DB::table('equipment')->count());
    }

    public function test_it_imports_sites_equipment_categories_and_employees(): void
    {
        $this->seedSource();

        $this->artisan('pss:import-master-data')->assertSuccessful();

        $this->assertSame(['1', '2'], DB::table('sites')->orderBy('source_id')->pluck('source_id')->all());
        $this->assertSame(['ACU', 'ATS', 'GENSET'], DB::table('equipment_categories')->orderBy('code')->pluck('code')->all());

        $genset = DB::table('equipment')->where('source_id', 'GENSET-1')->sole();
        $this->assertSame('GENSET', $genset->equipment_category_code);
        $this->assertSame('1', $genset->site_source_id);
        $this->assertSame('Genset 1', $genset->name);
        $this->assertSame('Basement', $genset->location);
        $this->assertSame('2022-03-15', $genset->installed_on);
        $this->assertSame('operational', $genset->operating_status);
        $this->assertSame(['brand' => 'Cummins', 'model' => 'C500', 'capacity_kva' => '500', 'fuel_type' => 'diesel'], json_decode($genset->details, true));
        $this->assertSame(1, (int) $genset->is_active);

        $this->assertSame('non-operational', DB::table('equipment')->where('source_id', 'ATS-1')->value('operating_status'));
        $this->assertSame(['ACU-1', 'ATS-1', 'GENSET-1'], DB::table('equipment')->orderBy('source_id')->pluck('source_id')->all());

        $requestor = DB::table('employees')->where('source_id', '1001')->sole();
        $this->assertSame('Rina Requestor', $requestor->name);
        $this->assertSame('Facilities Coordinator', $requestor->position_title);
        $this->assertSame('2001', $requestor->immediate_head_id);
    }

    public function test_no_building_category_or_building_equipment_is_imported(): void
    {
        $this->seedSource();

        $this->artisan('pss:import-master-data')->assertSuccessful();

        $this->assertFalse(DB::table('equipment')->where('name', 'Makati Office Building')->exists());
        $this->assertFalse(DB::table('equipment_categories')->where('name', 'like', '%Building%')->exists());
        $this->assertSame(3, DB::table('equipment')->count());
    }

    public function test_it_prints_a_summary_per_table(): void
    {
        $this->seedSource();

        $this->artisan('pss:import-master-data')
            ->expectsTable(['Table', 'Inserted', 'Updated', 'Deactivated'], [
                ['equipment_categories', 0, 0, 0],
                ['sites', 2, 0, 0],
                ['equipment', 3, 0, 0],
                ['employees', 2, 0, 0],
            ])
            ->assertSuccessful();
    }

    public function test_a_second_run_with_the_same_data_changes_nothing(): void
    {
        $this->seedSource();
        $this->artisan('pss:import-master-data')->assertSuccessful();
        $snapshot = DB::table('equipment')->orderBy('id')->get()->toArray();

        Carbon::setTestNow(now()->addDay());
        $this->artisan('pss:import-master-data')
            ->expectsTable(['Table', 'Inserted', 'Updated', 'Deactivated'], [
                ['equipment_categories', 0, 0, 0],
                ['sites', 0, 0, 0],
                ['equipment', 0, 0, 0],
                ['employees', 0, 0, 0],
            ])
            ->assertSuccessful();

        $this->assertEquals($snapshot, DB::table('equipment')->orderBy('id')->get()->toArray());
    }

    public function test_changed_rows_are_updated_by_source_id(): void
    {
        $this->seedSource();
        $this->artisan('pss:import-master-data')->assertSuccessful();
        $gensetId = DB::table('equipment')->where('source_id', 'GENSET-1')->value('id');

        $this->source()->table('gensets')->where('id', 1)->update(['name' => 'Genset 1 (renamed)', 'status' => 'broken', 'capacity_kva' => '600']);
        $this->source()->table('employees')->where('id', 1001)->update(['position_title' => 'Senior Coordinator']);

        $this->artisan('pss:import-master-data')
            ->expectsTable(['Table', 'Inserted', 'Updated', 'Deactivated'], [
                ['equipment_categories', 0, 0, 0],
                ['sites', 0, 0, 0],
                ['equipment', 0, 1, 0],
                ['employees', 0, 1, 0],
            ])
            ->assertSuccessful();

        $genset = DB::table('equipment')->where('source_id', 'GENSET-1')->sole();
        $this->assertSame($gensetId, $genset->id);
        $this->assertSame('Genset 1 (renamed)', $genset->name);
        $this->assertSame('non-operational', $genset->operating_status);
        $this->assertSame('600', json_decode($genset->details, true)['capacity_kva']);
        $this->assertSame('Senior Coordinator', DB::table('employees')->where('source_id', '1001')->value('position_title'));
    }

    public function test_rows_missing_from_the_source_become_inactive_and_come_back_active(): void
    {
        $this->seedSource();
        $this->artisan('pss:import-master-data')->assertSuccessful();

        $this->source()->table('gensets')->delete();
        $this->source()->table('sites')->where('id', 2)->delete();
        $this->source()->table('employees')->where('id', 1001)->delete();

        $this->artisan('pss:import-master-data')
            ->expectsTable(['Table', 'Inserted', 'Updated', 'Deactivated'], [
                ['equipment_categories', 0, 0, 0],
                ['sites', 0, 0, 1],
                ['equipment', 0, 0, 1],
                ['employees', 0, 0, 1],
            ])
            ->assertSuccessful();

        $this->assertSame(0, (int) DB::table('equipment')->where('source_id', 'GENSET-1')->value('is_active'));
        $this->assertSame(0, (int) DB::table('sites')->where('source_id', '2')->value('is_active'));
        $this->assertSame(0, (int) DB::table('employees')->where('source_id', '1001')->value('is_active'));
        $this->assertSame(3, DB::table('equipment')->count(), 'Inactive rows are never deleted.');

        $this->source()->table('gensets')->insert([
            'id' => 1, 'site_id' => 1, 'name' => 'Genset 1', 'location' => 'Basement', 'installed_on' => '2022-03-15',
            'status' => 'operational', 'brand' => 'Cummins', 'model' => 'C500', 'capacity_kva' => '500', 'fuel_type' => 'diesel',
        ]);

        $this->artisan('pss:import-master-data')->assertSuccessful();

        $this->assertSame(1, (int) DB::table('equipment')->where('source_id', 'GENSET-1')->value('is_active'));
        $this->assertSame(0, (int) DB::table('sites')->where('source_id', '2')->value('is_active'));
    }

    public function test_a_failing_source_read_writes_nothing(): void
    {
        $this->seedSource();
        $this->source()->getSchemaBuilder()->drop('ats');

        $this->artisan('pss:import-master-data')
            ->expectsOutputToContain('The master data import failed and changed nothing')
            ->assertFailed();

        $this->assertSame(0, DB::table('sites')->count());
        $this->assertSame(0, DB::table('equipment')->count());
        $this->assertSame(0, DB::table('employees')->count());
    }

    public function test_it_only_reads_from_the_source(): void
    {
        $this->seedSource();
        $writes = [];
        $this->source()->listen(function ($query) use (&$writes): void {
            if ($query->connectionName === 'master_source' && ! str_starts_with(strtolower(ltrim($query->sql)), 'select')) {
                $writes[] = $query->sql;
            }
        });

        $this->artisan('pss:import-master-data')->assertSuccessful();

        $this->assertSame([], $writes);
    }

    public function test_the_import_runs_nightly_at_2330_manila_before_pm_generation(): void
    {
        $event = collect($this->app->make(Schedule::class)->events())
            ->sole(fn (Event $event): bool => str_contains((string) $event->command, 'pss:import-master-data'));

        $this->assertSame('30 23 * * *', $event->expression);

        Carbon::setTestNow(Carbon::parse('2026-11-23 23:30:00', 'Asia/Manila'));
        $this->assertTrue($event->isDue($this->app));
    }

    public function test_the_categories_exist_after_migrating_even_before_any_import(): void
    {
        $this->assertSame(['ACU', 'ATS', 'GENSET'], DB::table('equipment_categories')->orderBy('code')->pluck('code')->all());
        $this->assertSame(ImportMasterData::CONNECTION, 'master_source');
    }
}
