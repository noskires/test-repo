<?php

namespace Tests\Feature;

use App\Enums\PmFrequency;
use App\Enums\PmStatus;
use App\Events\SitePmFrequencyChanged;
use App\Jobs\GeneratePmTasks;
use App\MasterData\Data\Equipment;
use App\MasterData\Data\EquipmentCategory;
use App\MasterData\Data\Site;
use App\MasterData\InMemoryMasterDataDirectory;
use App\MasterData\MasterDataDirectory;
use App\Models\ChecklistTemplate;
use App\Models\ChecklistTemplateVersion;
use App\Models\PmTask;
use App\Models\SiteSetting;
use App\Models\User;
use App\Models\Vendor;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PmTaskGenerationTest extends TestCase
{
    use RefreshDatabase;

    private InMemoryMasterDataDirectory $directory;

    private Vendor $vendor;

    private ChecklistTemplateVersion $airconChecklist;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = $this->app->make(MasterDataDirectory::class);
        $this->directory
            ->addSite(new Site(id: 'S-1', name: 'Makati Office'))
            ->addEquipmentCategory(new EquipmentCategory(id: 'CAT-AC', name: 'Aircon'))
            ->addEquipmentCategory(new EquipmentCategory(id: 'CAT-GEN', name: 'Genset'))
            ->addEquipment($this->equipment('EQ-1', installedOn: '2026-01-15', location: '2F Lobby'));

        $this->vendor = Vendor::factory()->create();
        SiteSetting::factory()->create([
            'site_id' => 'S-1',
            'site_name_snapshot' => 'Makati Office',
            'vendor_id' => $this->vendor->id,
            'eic_employee_id' => 'P-1',
            'pm_frequency' => PmFrequency::Monthly,
        ]);

        $template = ChecklistTemplate::create(['key' => 'aircon', 'name' => 'Aircon PM', 'equipment_category_id' => 'CAT-AC', 'is_active' => true]);
        $template->versions()->create(['version_number' => 1, 'content_fingerprint' => str_repeat('a', 64)]);
        $this->airconChecklist = $template->versions()->create(['version_number' => 2, 'content_fingerprint' => str_repeat('b', 64)]);
    }

    private function equipment(string $id, ?string $installedOn, string $siteId = 'S-1', string $categoryId = 'CAT-AC', ?string $location = null): Equipment
    {
        return new Equipment(
            id: $id,
            siteId: $siteId,
            name: "Unit {$id}",
            categoryId: $categoryId,
            location: $location,
            installedOn: $installedOn === null ? null : new DateTimeImmutable($installedOn),
        );
    }

    private function runJob(): void
    {
        GeneratePmTasks::dispatch();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function completedTask(string $equipmentId, string $workDoneDate, array $attributes = []): PmTask
    {
        return PmTask::factory()->completed(CarbonImmutable::parse($workDoneDate))->create([
            'site_id' => 'S-1',
            'equipment_id' => $equipmentId,
            'vendor_id' => $this->vendor->id,
            ...$attributes,
        ]);
    }

    public function test_the_job_creates_a_scheduled_task_with_routing_checklist_and_snapshots(): void
    {
        $this->runJob();

        $task = PmTask::query()->sole();
        $this->assertSame(PmStatus::Scheduled, $task->status);
        $this->assertSame('S-1', $task->site_id);
        $this->assertSame('Makati Office', $task->site_name_snapshot);
        $this->assertSame('EQ-1', $task->equipment_id);
        $this->assertSame('Unit EQ-1', $task->equipment_name_snapshot);
        $this->assertSame('CAT-AC', $task->equipment_category_id);
        $this->assertSame('Aircon', $task->equipment_category_snapshot);
        $this->assertSame('2F Lobby', $task->equipment_location_snapshot);
        $this->assertSame($this->vendor->id, $task->vendor_id);
        $this->assertSame('P-1', $task->eic_employee_id);
        $this->assertSame($this->airconChecklist->id, $task->checklist_template_version_id);
        $this->assertFalse($task->no_checklist);
        $this->assertFalse($task->equipment_removed);
        $this->assertSame('2026-02-15', $task->due_date->toDateString());
        $this->assertSame('2026-01-15', $task->due_from_date->toDateString());
        $this->assertNull($task->work_done_date);
    }

    public function test_the_due_date_uses_the_sites_frequency(): void
    {
        SiteSetting::query()->where('site_id', 'S-1')->update(['pm_frequency' => PmFrequency::Quarterly->value]);
        $this->directory->addEquipment($this->equipment('EQ-2', installedOn: '2026-11-30'));

        $this->runJob();

        $this->assertSame('2027-02-28', PmTask::query()->where('equipment_id', 'EQ-2')->sole()->due_date->toDateString());
    }

    public function test_one_task_is_created_per_eligible_equipment(): void
    {
        $this->directory
            ->addEquipment($this->equipment('EQ-2', installedOn: '2025-06-01'))
            ->addEquipment($this->equipment('EQ-3', installedOn: '2025-07-01'));

        $this->runJob();

        $this->assertSame(['EQ-1', 'EQ-2', 'EQ-3'], PmTask::query()->orderBy('equipment_id')->pluck('equipment_id')->all());
    }

    public function test_running_the_job_twice_creates_no_duplicates(): void
    {
        $this->runJob();
        $this->runJob();

        $this->assertSame(1, PmTask::query()->count());
    }

    public function test_the_database_rejects_a_second_open_task_for_the_same_equipment(): void
    {
        PmTask::factory()->create(['equipment_id' => 'EQ-9', 'status' => PmStatus::Scheduled]);

        $this->expectException(UniqueConstraintViolationException::class);

        PmTask::factory()->create(['equipment_id' => 'EQ-9', 'status' => PmStatus::InProgress]);
    }

    public function test_closed_tasks_do_not_block_a_new_open_task(): void
    {
        PmTask::factory()->completed(CarbonImmutable::parse('2026-01-01'))->create(['equipment_id' => 'EQ-9']);
        PmTask::factory()->create(['equipment_id' => 'EQ-9', 'status' => PmStatus::Cancelled]);
        PmTask::factory()->create(['equipment_id' => 'EQ-9', 'status' => PmStatus::Scheduled]);

        $this->assertSame(3, PmTask::query()->where('equipment_id', 'EQ-9')->count());
    }

    public function test_a_concurrent_run_that_inserts_first_does_not_cause_a_duplicate_or_a_failure(): void
    {
        $competingRunInserted = false;
        DB::listen(function ($query) use (&$competingRunInserted): void {
            if ($competingRunInserted || ! str_contains(strtolower($query->sql), 'max(')) {
                return;
            }

            // Another run commits EQ-1's open task after this run checked for one, but before it inserts.
            $competingRunInserted = true;
            PmTask::factory()->create(['site_id' => 'S-1', 'equipment_id' => 'EQ-1', 'vendor_id' => $this->vendor->id]);
        });

        $this->runJob();

        $this->assertTrue($competingRunInserted);
        $this->assertSame(1, PmTask::query()->where('equipment_id', 'EQ-1')->count());
    }

    public function test_a_completed_task_gets_a_successor_due_from_its_work_done_date(): void
    {
        $completed = $this->completedTask('EQ-1', '2026-03-31');

        $this->runJob();

        $this->assertModelExists($completed);
        $successor = PmTask::query()->where('status', PmStatus::Scheduled)->sole();
        $this->assertSame('EQ-1', $successor->equipment_id);
        $this->assertSame('2026-04-30', $successor->due_date->toDateString());
        $this->assertSame('2026-03-31', $successor->due_from_date->toDateString());
        $this->assertSame(2, PmTask::query()->count());
    }

    public function test_the_successor_follows_the_latest_completed_task(): void
    {
        $this->completedTask('EQ-1', '2026-02-10');
        $this->completedTask('EQ-1', '2026-03-12');

        $this->runJob();

        $this->assertSame('2026-04-12', PmTask::query()->where('status', PmStatus::Scheduled)->sole()->due_date->toDateString());
    }

    public function test_an_open_task_blocks_a_successor(): void
    {
        $this->completedTask('EQ-1', '2026-02-10');
        PmTask::factory()->create(['site_id' => 'S-1', 'equipment_id' => 'EQ-1', 'status' => PmStatus::InProgress, 'vendor_id' => $this->vendor->id]);

        $this->runJob();

        $this->assertSame(2, PmTask::query()->count());
        $this->assertSame(0, PmTask::query()->where('status', PmStatus::Scheduled)->count());
    }

    public function test_equipment_with_no_installation_date_and_no_history_gets_no_task_until_one_appears(): void
    {
        $this->directory->addEquipment($this->equipment('EQ-NEW', installedOn: null));

        $this->runJob();

        $this->assertSame(0, PmTask::query()->where('equipment_id', 'EQ-NEW')->count());

        $this->directory->addEquipment($this->equipment('EQ-NEW', installedOn: '2026-05-20'));
        $this->runJob();

        $this->assertSame('2026-06-20', PmTask::query()->where('equipment_id', 'EQ-NEW')->sole()->due_date->toDateString());
    }

    public function test_equipment_with_no_installation_date_but_pm_history_gets_a_successor(): void
    {
        $this->directory->addEquipment($this->equipment('EQ-OLD', installedOn: null));
        $this->completedTask('EQ-OLD', '2026-06-01');

        $this->runJob();

        $this->assertSame('2026-07-01', PmTask::query()->where('equipment_id', 'EQ-OLD')->where('status', PmStatus::Scheduled)->sole()->due_date->toDateString());
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function incompleteSiteSettings(): array
    {
        return [
            'no pm frequency' => [['pm_frequency' => null]],
            'no site vendor' => [['vendor_id' => null]],
            'no eic' => [['eic_employee_id' => null]],
        ];
    }

    /**
     * @param  array<string, mixed>  $missing
     */
    #[DataProvider('incompleteSiteSettings')]
    public function test_a_site_missing_frequency_vendor_or_eic_is_skipped(array $missing): void
    {
        SiteSetting::query()->where('site_id', 'S-1')->update($missing);

        $this->runJob();

        $this->assertSame(0, PmTask::query()->count());
    }

    public function test_a_site_with_no_settings_row_is_skipped(): void
    {
        $this->directory
            ->addSite(new Site(id: 'S-2', name: 'Cebu Site'))
            ->addEquipment($this->equipment('EQ-CEBU', installedOn: '2026-01-01', siteId: 'S-2'));

        $this->runJob();

        $this->assertSame(['EQ-1'], PmTask::query()->pluck('equipment_id')->all());
    }

    public function test_a_category_with_no_active_checklist_still_gets_a_task_flagged_no_checklist(): void
    {
        $this->directory->addEquipment($this->equipment('EQ-GEN', installedOn: '2026-01-15', categoryId: 'CAT-GEN'));

        $this->runJob();

        $task = PmTask::query()->where('equipment_id', 'EQ-GEN')->sole();
        $this->assertTrue($task->no_checklist);
        $this->assertNull($task->checklist_template_version_id);
        $this->assertSame(PmStatus::Scheduled, $task->status);
    }

    public function test_an_inactive_checklist_counts_as_no_checklist(): void
    {
        ChecklistTemplate::query()->update(['is_active' => false]);

        $this->runJob();

        $task = PmTask::query()->sole();
        $this->assertTrue($task->no_checklist);
        $this->assertNull($task->checklist_template_version_id);
    }

    public function test_an_open_task_whose_equipment_was_removed_is_kept_and_flagged_not_cancelled(): void
    {
        $this->runJob();
        $task = PmTask::query()->sole();

        $emptiedDirectory = (new InMemoryMasterDataDirectory)->addSite(new Site(id: 'S-1', name: 'Makati Office'));
        $this->app->instance(MasterDataDirectory::class, $emptiedDirectory);
        $this->runJob();

        $task->refresh();
        $this->assertTrue($task->equipment_removed);
        $this->assertSame(PmStatus::Scheduled, $task->status);
        $this->assertSame('Unit EQ-1', $task->equipment_name_snapshot);
        $this->assertSame(1, PmTask::query()->count());
    }

    public function test_the_removed_flag_also_applies_at_a_site_that_is_now_skipped(): void
    {
        $this->runJob();
        SiteSetting::query()->where('site_id', 'S-1')->update(['vendor_id' => null]);
        $this->app->instance(MasterDataDirectory::class, new InMemoryMasterDataDirectory);

        $this->runJob();

        $this->assertTrue(PmTask::query()->sole()->equipment_removed);
    }

    public function test_the_removed_flag_clears_when_the_equipment_comes_back(): void
    {
        $this->runJob();
        $this->app->instance(MasterDataDirectory::class, new InMemoryMasterDataDirectory);
        $this->runJob();

        $this->app->instance(MasterDataDirectory::class, $this->directory);
        $this->runJob();

        $this->assertFalse(PmTask::query()->sole()->equipment_removed);
    }

    private function inactive(Equipment $equipment): Equipment
    {
        return new Equipment(
            id: $equipment->id,
            siteId: $equipment->siteId,
            name: $equipment->name,
            categoryId: $equipment->categoryId,
            location: $equipment->location,
            installedOn: $equipment->installedOn,
            isActive: false,
        );
    }

    public function test_inactive_equipment_gets_no_new_pm_task(): void
    {
        $this->directory->addEquipment($this->inactive($this->equipment('EQ-1', installedOn: '2026-01-15')));

        $this->runJob();

        $this->assertSame(0, PmTask::query()->count());
    }

    public function test_a_completed_task_of_equipment_that_went_inactive_gets_no_successor(): void
    {
        $this->completedTask('EQ-1', '2026-03-01');
        $this->directory->addEquipment($this->inactive($this->equipment('EQ-1', installedOn: '2026-01-15')));

        $this->runJob();

        $this->assertSame(0, PmTask::query()->open()->count());
    }

    public function test_an_open_task_of_equipment_that_went_inactive_is_kept_flagged_and_still_reads_its_snapshots(): void
    {
        $this->runJob();
        $this->directory->addEquipment($this->inactive($this->equipment('EQ-1', installedOn: '2026-01-15')));

        $this->runJob();

        $task = PmTask::query()->sole();
        $this->assertTrue($task->equipment_removed);
        $this->assertSame(PmStatus::Scheduled, $task->status);
        $this->assertSame('Unit EQ-1', $task->equipment_name_snapshot);
        $this->assertSame('Makati Office', $task->site_name_snapshot);
    }

    public function test_changing_the_frequency_re_dates_only_scheduled_tasks(): void
    {
        $this->directory
            ->addEquipment($this->equipment('EQ-2', installedOn: '2026-01-31'))
            ->addEquipment($this->equipment('EQ-3', installedOn: '2026-01-10'))
            ->addSite(new Site(id: 'S-2', name: 'Cebu Site'))
            ->addEquipment($this->equipment('EQ-CEBU', installedOn: '2026-01-15', siteId: 'S-2'));
        SiteSetting::factory()->create(['site_id' => 'S-2', 'vendor_id' => $this->vendor->id, 'eic_employee_id' => 'P-2', 'pm_frequency' => PmFrequency::Monthly]);
        $this->completedTask('EQ-2', '2026-03-31');
        $this->runJob();

        $inProgress = PmTask::query()->where('equipment_id', 'EQ-3')->sole();
        $inProgress->update(['status' => PmStatus::InProgress]);

        $this->actingAs(User::factory()->create());
        Gate::define('manage-site-settings', fn (): bool => true);
        $this->putJson('/api/sites/S-1/settings/pm-frequency', ['pm_frequency' => 'quarterly'])->assertOk();

        $this->assertSame('2026-04-15', PmTask::query()->where('equipment_id', 'EQ-1')->sole()->due_date->toDateString(), 'installedOn 2026-01-15 + 3 months');
        $this->assertSame('2026-06-30', PmTask::query()->where('equipment_id', 'EQ-2')->where('status', PmStatus::Scheduled)->sole()->due_date->toDateString(), 'Work Done 2026-03-31 + 3 months');
        $this->assertSame('2026-02-10', $inProgress->refresh()->due_date->toDateString(), 'In Progress keeps its due date');
        $this->assertSame('2026-02-15', PmTask::query()->where('equipment_id', 'EQ-CEBU')->sole()->due_date->toDateString(), 'Other Sites keep their due date');
        $this->assertSame('2026-03-31', PmTask::query()->where('equipment_id', 'EQ-2')->where('status', PmStatus::Completed)->sole()->work_done_date->toDateString());
    }

    /**
     * @return array<string, array{PmStatus}>
     */
    public static function openStatusesThatKeepTheirDueDate(): array
    {
        return [
            'in progress' => [PmStatus::InProgress],
            'for validation' => [PmStatus::ForValidation],
            'returned' => [PmStatus::Returned],
        ];
    }

    #[DataProvider('openStatusesThatKeepTheirDueDate')]
    public function test_a_frequency_change_leaves_non_scheduled_tasks_alone(PmStatus $status): void
    {
        $this->runJob();
        PmTask::query()->update(['status' => $status->value]);

        SitePmFrequencyChanged::dispatch('S-1', PmFrequency::Monthly, PmFrequency::Annual);

        $this->assertSame('2026-02-15', PmTask::query()->sole()->due_date->toDateString());
    }

    public function test_the_job_is_queued(): void
    {
        Bus::fake();

        GeneratePmTasks::dispatch();

        Bus::assertDispatched(GeneratePmTasks::class);
        $this->assertContains(ShouldQueue::class, class_implements(GeneratePmTasks::class));
    }

    public function test_the_job_is_scheduled_daily(): void
    {
        $events = collect($this->app->make(Schedule::class)->events())
            ->filter(fn ($event): bool => str_contains((string) $event->description, GeneratePmTasks::class));

        $this->assertCount(1, $events);
        $this->assertSame('0 0 * * *', $events->first()->expression);
    }

    public function test_the_open_key_is_cleared_when_a_task_is_completed(): void
    {
        $this->runJob();
        $task = PmTask::query()->sole();

        $task->update(['status' => PmStatus::Completed, 'work_done_date' => '2026-02-14']);

        $this->assertNull(DB::table('pm_tasks')->where('id', $task->id)->value('open_equipment_id'));
    }
}
