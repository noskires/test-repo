<?php

namespace Tests\Feature;

use App\Enums\PmFrequency;
use App\Enums\PmStatus;
use App\Jobs\GeneratePmTasks;
use App\MasterData\Data\Equipment;
use App\MasterData\Data\EquipmentCategory;
use App\MasterData\Data\Site;
use App\MasterData\InMemoryMasterDataDirectory;
use App\MasterData\MasterDataDirectory;
use App\Models\PmTask;
use App\Models\SiteSetting;
use App\Models\User;
use App\Models\Vendor;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * PSS runs on Asia/Manila time (ADR 0003). Each boundary test freezes time inside 00:00-08:00 Manila,
 * where the UTC calendar date is still the previous day, so a rule evaluated in UTC would fail.
 */
class ApplicationTimezoneTest extends TestCase
{
    use RefreshDatabase;

    private Vendor $vendor;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('pss.e_call_attachments.disk'));
        $this->vendor = Vendor::factory()->create();
    }

    private function freezeAt(string $instant, string $timezone): void
    {
        $this->travelTo(CarbonImmutable::parse($instant, $timezone));
    }

    private function vendorTask(PmStatus $status): PmTask
    {
        return PmTask::factory()->create(['vendor_id' => $this->vendor->id, 'status' => $status, 'no_checklist' => true]);
    }

    private function submitReport(PmTask $task, string $workDoneDate): TestResponse
    {
        return $this->post("/api/pm-tasks/{$task->id}/report", [
            'work_done_date' => $workDoneDate,
            'service_report' => UploadedFile::fake()->create('service-report.pdf', 20, 'application/pdf'),
        ], ['Accept' => 'application/json']);
    }

    public function test_the_application_timezone_is_asia_manila(): void
    {
        $this->assertSame('Asia/Manila', config('app.timezone'));
        $this->assertSame('Asia/Manila', date_default_timezone_get());
        $this->assertSame('Asia/Manila', now()->getTimezone()->getName());
    }

    public function test_the_config_default_is_asia_manila_when_app_timezone_is_not_set(): void
    {
        $this->assertFalse(getenv('APP_TIMEZONE'), 'APP_TIMEZONE must not be set for the test run (phpunit.xml or .env).');

        $config = require base_path('config/app.php');

        $this->assertSame('Asia/Manila', $config['timezone']);
    }

    public function test_env_example_sets_app_timezone_to_asia_manila(): void
    {
        $this->assertMatchesRegularExpression('/^APP_TIMEZONE=Asia\/Manila$/m', file_get_contents(base_path('.env.example')));
    }

    public function test_phpunit_does_not_override_the_timezone(): void
    {
        $this->assertStringNotContainsString('APP_TIMEZONE', file_get_contents(base_path('phpunit.xml')));
    }

    public function test_no_hard_coded_utc_timezone_in_app_code(): void
    {
        $offenders = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path())) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php' && preg_match('/[\'"]UTC[\'"]/i', file_get_contents($file->getPathname())) === 1) {
                $offenders[] = $file->getPathname();
            }
        }

        $this->assertSame([], $offenders);
    }

    public function test_not_in_the_future_uses_the_manila_date(): void
    {
        $this->freezeAt('2026-11-23 01:00:00', 'Asia/Manila');
        $this->assertSame('2026-11-22', now()->utc()->toDateString(), 'Sanity check: UTC is still on the previous day.');
        $this->actingAs(User::factory()->vendorStaff($this->vendor)->create());

        $this->submitReport($this->vendorTask(PmStatus::InProgress), '2026-11-24')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['work_done_date' => 'The Work Done Date cannot be in the future.']);

        $this->submitReport($this->vendorTask(PmStatus::InProgress), '2026-11-23')->assertCreated();
    }

    public function test_not_before_start_uses_the_manila_date_of_the_start(): void
    {
        $this->actingAs(User::factory()->vendorStaff($this->vendor)->create());
        $task = $this->vendorTask(PmStatus::Scheduled);

        $this->freezeAt('2026-11-22 23:30:00', 'UTC');
        $this->postJson("/api/pm-tasks/{$task->id}/start")->assertOk();
        $this->assertSame('2026-11-23 07:30:00', $task->refresh()->started_at->format('Y-m-d H:i:s'), 'Stored as Manila wall-clock time.');

        $this->freezeAt('2026-11-23 15:00:00', 'Asia/Manila');

        $this->submitReport($task, '2026-11-22')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['work_done_date' => 'The Work Done Date cannot be before the PM Task was started (2026-11-23).']);

        $this->submitReport($task, '2026-11-23')->assertCreated();
    }

    private function seedSiteWithEquipment(string $installedOn): void
    {
        /** @var InMemoryMasterDataDirectory $directory */
        $directory = $this->app->make(MasterDataDirectory::class);
        $directory
            ->addSite(new Site(id: 'S-1', name: 'Makati Office'))
            ->addEquipmentCategory(new EquipmentCategory(id: 'CAT-AC', name: 'Aircon'))
            ->addEquipment(new Equipment(id: 'EQ-1', siteId: 'S-1', name: 'Lobby Aircon', categoryId: 'CAT-AC', installedOn: new DateTimeImmutable($installedOn)));

        SiteSetting::factory()->create([
            'site_id' => 'S-1',
            'vendor_id' => $this->vendor->id,
            'eic_employee_id' => 'P-1',
            'pm_frequency' => PmFrequency::Monthly,
        ]);
    }

    public function test_pm_generation_in_the_early_manila_hours_uses_the_manila_calendar_date(): void
    {
        $this->seedSiteWithEquipment('2026-11-23');
        $this->freezeAt('2026-11-23 01:00:00', 'Asia/Manila');

        GeneratePmTasks::dispatch();

        $task = PmTask::query()->sole();
        $this->assertSame('2026-11-23', $task->due_from_date->toDateString());
        $this->assertSame('2026-12-23', $task->due_date->toDateString());
        $this->assertSame(
            PmFrequency::Monthly->nextDueDate(CarbonImmutable::parse('2026-11-23'))->toDateString(),
            $task->due_date->toDateString(),
        );
    }

    public function test_a_successor_counts_from_the_work_done_date_as_a_manila_date(): void
    {
        $this->seedSiteWithEquipment('2026-01-15');
        PmTask::factory()->completed(CarbonImmutable::parse('2026-11-23'))->create([
            'site_id' => 'S-1',
            'equipment_id' => 'EQ-1',
            'vendor_id' => $this->vendor->id,
        ]);
        $this->freezeAt('2026-11-23 02:00:00', 'Asia/Manila');

        GeneratePmTasks::dispatch();

        $successor = PmTask::query()->where('status', PmStatus::Scheduled)->sole();
        $this->assertSame('2026-11-23', $successor->due_from_date->toDateString());
        $this->assertSame('2026-12-23', $successor->due_date->toDateString());
    }

    private function pmGenerationEvent(): Event
    {
        return collect($this->app->make(Schedule::class)->events())
            ->sole(fn (Event $event): bool => str_contains((string) $event->description, GeneratePmTasks::class));
    }

    public function test_the_daily_pm_generation_runs_at_manila_midnight(): void
    {
        $event = $this->pmGenerationEvent();

        $this->freezeAt('2026-11-23 00:00:00', 'Asia/Manila');
        $this->assertTrue($event->isDue($this->app), 'Due at 00:00 Manila (16:00 UTC the day before).');

        $this->freezeAt('2026-11-23 00:00:00', 'UTC');
        $this->assertFalse($event->isDue($this->app), 'Not due at 00:00 UTC (08:00 Manila).');

        $this->assertSame('2026-11-24 00:00:00', $event->nextRunDate(now())->format('Y-m-d H:i:s'));
    }
}
