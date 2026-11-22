<?php

namespace Tests\Feature;

use App\Enums\PmStatus;
use App\Models\ChecklistItem;
use App\Models\ChecklistTemplate;
use App\Models\ChecklistTemplateVersion;
use App\Models\PmReport;
use App\Models\PmTask;
use App\Models\User;
use App\Models\Vendor;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PmReportApiTest extends TestCase
{
    use RefreshDatabase;

    private Vendor $vendor;

    private ChecklistTemplateVersion $checklist;

    private ChecklistItem $engineOil;

    private ChecklistItem $coolant;

    private ChecklistItem $belt;

    private ChecklistItem $areaPhoto;

    private ChecklistItem $notes;

    private ChecklistItem $alarm;

    private PmTask $task;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('pss.e_call_attachments.disk'));
        Carbon::setTestNow('2026-11-22 10:00:00');

        $template = ChecklistTemplate::create(['key' => 'genset', 'name' => 'Genset PM', 'equipment_category_id' => 'CAT-GEN', 'is_active' => true]);
        $this->checklist = $template->versions()->create(['version_number' => 1, 'content_fingerprint' => str_repeat('a', 64)]);
        $this->engineOil = $this->item(1, 'Engine oil inspection', 'pass_fail', mandatory: true, remarksRequiredOnFail: true);
        $this->coolant = $this->item(2, 'Coolant level', 'numeric', mandatory: true, remarksRequiredOnFail: true, min: '10', max: '20.5');
        $this->belt = $this->item(3, 'Belt condition', 'good_defective', photoRequired: true);
        $this->areaPhoto = $this->item(4, 'Photo of the genset area', 'photo', mandatory: true);
        $this->notes = $this->item(5, 'Other notes', 'text');
        $this->alarm = $this->item(6, 'Alarm works', 'yes_no', mandatory: true);

        $this->vendor = Vendor::factory()->create();
        $this->task = PmTask::factory()->create([
            'site_id' => 'S-1',
            'vendor_id' => $this->vendor->id,
            'checklist_template_version_id' => $this->checklist->id,
            'status' => PmStatus::InProgress,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function item(int $position, string $label, string $type, bool $mandatory = false, bool $remarksRequiredOnFail = false, bool $photoRequired = false, ?string $min = null, ?string $max = null): ChecklistItem
    {
        return $this->checklist->items()->create([
            'position' => $position,
            'label' => $label,
            'response_type' => $type,
            'mandatory' => $mandatory,
            'remarks_required_on_fail' => $remarksRequiredOnFail,
            'photo_required' => $photoRequired,
            'acceptable_min' => $min,
            'acceptable_max' => $max,
        ]);
    }

    private function actingAsVendorStaff(?Vendor $vendor = null): User
    {
        $user = User::factory()->vendorStaff($vendor ?? $this->vendor)->create();
        $this->actingAs($user);

        return $user;
    }

    /**
     * @return array<int|string, array<string, mixed>>
     */
    private function validResponses(): array
    {
        return [
            $this->engineOil->id => ['value' => 'pass'],
            $this->coolant->id => ['value' => '15.25'],
            $this->belt->id => ['value' => 'good', 'photos' => [UploadedFile::fake()->image('belt.jpg')]],
            $this->areaPhoto->id => ['photos' => [UploadedFile::fake()->image('area-1.jpg'), UploadedFile::fake()->image('area-2.png')]],
            $this->alarm->id => ['value' => 'yes'],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validReport(array $overrides = []): array
    {
        return array_merge([
            'work_done_date' => '2026-11-20',
            'findings' => 'Replaced the air filter. Minor oil seepage observed.',
            'responses' => $this->validResponses(),
            'materials' => [
                ['description' => 'Air filter', 'quantity' => '1'],
                ['description' => 'Engine oil', 'quantity' => '2.5'],
            ],
            'service_report' => UploadedFile::fake()->create('pm-service-report.pdf', 200, 'application/pdf'),
            'attachments' => [UploadedFile::fake()->create('site-sketch.pdf', 50, 'application/pdf')],
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function submitReport(array $payload, ?PmTask $task = null): TestResponse
    {
        $task ??= $this->task;

        return $this->post("/api/pm-tasks/{$task->id}/report", $payload, ['Accept' => 'application/json']);
    }

    /**
     * Assert a 422 with an error on the (dotted) key whose first message names the item.
     */
    private function assertErrorNamesItem(TestResponse $response, string $key, string $label): void
    {
        $response->assertUnprocessable()->assertJsonValidationErrors([$key]);

        $this->assertStringContainsString($label, $response->json('errors')[$key][0]);
    }

    public function test_vendor_staff_list_only_their_vendors_tasks(): void
    {
        PmTask::factory()->create(['vendor_id' => Vendor::factory()->create()->id]);
        $alsoMine = PmTask::factory()->create(['vendor_id' => $this->vendor->id, 'status' => PmStatus::Scheduled]);
        $this->actingAsVendorStaff();

        $ids = collect($this->getJson('/api/vendor/pm-tasks')->assertOk()->json('data'))->pluck('id')->sort()->values()->all();

        $this->assertSame([$this->task->id, $alsoMine->id], $ids);
    }

    public function test_the_task_list_shows_the_task_details(): void
    {
        $this->actingAsVendorStaff();

        $this->getJson('/api/vendor/pm-tasks')
            ->assertOk()
            ->assertJsonPath('data.0.id', $this->task->id)
            ->assertJsonPath('data.0.status', 'in_progress')
            ->assertJsonPath('data.0.site.id', 'S-1')
            ->assertJsonPath('data.0.equipment.id', $this->task->equipment_id)
            ->assertJsonPath('data.0.checklist_template_version_id', $this->checklist->id)
            ->assertJsonPath('data.0.due_date', $this->task->due_date->toDateString());
    }

    public function test_a_user_who_is_not_vendor_staff_cannot_list_vendor_tasks(): void
    {
        $this->actingAs(User::factory()->create());

        $this->getJson('/api/vendor/pm-tasks')->assertForbidden();
    }

    public function test_starting_a_scheduled_task_moves_it_to_in_progress(): void
    {
        $this->task->update(['status' => PmStatus::Scheduled]);
        $this->actingAsVendorStaff();

        $this->postJson("/api/pm-tasks/{$this->task->id}/start")
            ->assertOk()
            ->assertJsonPath('data.status', 'in_progress')
            ->assertJsonPath('data.started_at', '2026-11-22T02:00:00.000000Z', 'API timestamps are ISO-8601 UTC: 10:00 Manila = 02:00Z.');

        $this->task->refresh();
        $this->assertSame(PmStatus::InProgress, $this->task->status);
        $this->assertSame('2026-11-22 10:00:00', $this->task->started_at->format('Y-m-d H:i:s'));
    }

    public function test_the_task_resource_returns_a_null_started_at_for_a_task_never_started_through_the_api(): void
    {
        $this->actingAsVendorStaff();

        $this->getJson('/api/vendor/pm-tasks')
            ->assertOk()
            ->assertJsonPath('data.0.started_at', null);
    }

    /**
     * @return array<string, array{PmStatus}>
     */
    public static function statusesThatCannotStart(): array
    {
        return [
            'in progress' => [PmStatus::InProgress],
            'for validation' => [PmStatus::ForValidation],
            'returned' => [PmStatus::Returned],
            'completed' => [PmStatus::Completed],
            'cancelled' => [PmStatus::Cancelled],
        ];
    }

    #[DataProvider('statusesThatCannotStart')]
    public function test_start_works_only_from_scheduled(PmStatus $status): void
    {
        $this->task->update(['status' => $status]);
        $this->actingAsVendorStaff();

        $this->postJson("/api/pm-tasks/{$this->task->id}/start")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['pm_task']);

        $this->assertSame($status, $this->task->refresh()->status);
    }

    /**
     * @return array<string, array{Closure(): User}>
     */
    public static function usersWhoAreNotTheTasksVendor(): array
    {
        return [
            'staff of another vendor' => [fn (): User => User::factory()->vendorStaff()->create()],
            'a non-vendor user' => [fn (): User => User::factory()->create(['employee_id' => 'P-1'])],
        ];
    }

    /**
     * @param  Closure(): User  $makeUser
     */
    #[DataProvider('usersWhoAreNotTheTasksVendor')]
    public function test_another_vendors_task_returns_403_for_start(Closure $makeUser): void
    {
        $this->task->update(['status' => PmStatus::Scheduled]);
        $this->actingAs($makeUser());

        $this->postJson("/api/pm-tasks/{$this->task->id}/start")->assertForbidden();

        $this->assertSame(PmStatus::Scheduled, $this->task->refresh()->status);
    }

    /**
     * @param  Closure(): User  $makeUser
     */
    #[DataProvider('usersWhoAreNotTheTasksVendor')]
    public function test_another_vendors_task_returns_403_for_report(Closure $makeUser): void
    {
        $this->actingAs($makeUser());

        $this->submitReport($this->validReport())->assertForbidden();

        $this->assertDatabaseCount('pm_reports', 0);
        $this->assertSame(PmStatus::InProgress, $this->task->refresh()->status);
    }

    public function test_a_valid_report_stores_responses_attachments_and_moves_the_task_to_for_validation(): void
    {
        $user = $this->actingAsVendorStaff();

        $this->submitReport($this->validReport([
            'responses' => $this->validResponses() + [$this->notes->id => ['value' => 'All good otherwise.']],
        ]))
            ->assertJsonMissingPath('errors')
            ->assertCreated()
            ->assertJsonPath('data.revision', 1)
            ->assertJsonPath('data.is_current', true)
            ->assertJsonPath('data.work_done_date', '2026-11-20')
            ->assertJsonPath('data.checklist_template_version_id', $this->checklist->id)
            ->assertJsonPath('data.submitted_by_user_id', $user->id)
            ->assertJsonPath('data.materials.1.description', 'Engine oil')
            ->assertJsonPath('data.materials.1.quantity', '2.500')
            ->assertJsonCount(1, 'data.attachments')
            ->assertJsonPath('data.attachments.0.original_name', 'site-sketch.pdf')
            ->assertJsonPath('data.service_report.original_name', 'pm-service-report.pdf')
            ->assertJsonPath('data.service_report.mime_type', 'application/pdf')
            ->assertJsonCount(3, 'data.photos');

        $this->task->refresh();
        $this->assertSame(PmStatus::ForValidation, $this->task->status);
        $this->assertSame('2026-11-20', $this->task->work_done_date->toDateString());

        $report = PmReport::query()->with(['responses', 'attachments', 'materials'])->sole();
        $this->assertSame($this->checklist->id, $report->checklist_template_version_id);
        $this->assertSame('Replaced the air filter. Minor oil seepage observed.', $report->findings);
        $this->assertNotNull($report->submitted_at);

        $responses = $report->responses->keyBy('checklist_item_id');
        $this->assertCount(6, $responses);
        $this->assertSame('pass', $responses[$this->engineOil->id]->value);
        $this->assertSame('15.25', $responses[$this->coolant->id]->value);
        $this->assertFalse($responses[$this->coolant->id]->is_failed);
        $this->assertSame('good', $responses[$this->belt->id]->value);
        $this->assertNull($responses[$this->areaPhoto->id]->value);
        $this->assertSame('All good otherwise.', $responses[$this->notes->id]->value);

        $this->assertCount(4, $report->attachments);
        $this->assertSame(1, $report->attachments->where('checklist_item_id', $this->belt->id)->count());
        $this->assertSame(2, $report->attachments->where('checklist_item_id', $this->areaPhoto->id)->count());
        $this->assertSame(1, $report->attachments->whereNull('checklist_item_id')->count());
        $this->assertSame('pm-service-report.pdf', $report->service_report_original_name);
        Storage::disk(config('pss.e_call_attachments.disk'))->assertExists($report->service_report_path);
        $this->assertNotContains($report->service_report_path, $report->attachments->pluck('path')->all());
        foreach ($report->attachments as $attachment) {
            Storage::disk(config('pss.e_call_attachments.disk'))->assertExists($attachment->path);
        }

        $this->assertSame([1_000, 2_500], $report->materials->pluck('quantity_thousandths')->all());
    }

    public function test_a_report_can_be_submitted_from_returned(): void
    {
        $this->task->update(['status' => PmStatus::Returned]);
        $this->actingAsVendorStaff();

        $this->submitReport($this->validReport())->assertCreated();

        $this->assertSame(PmStatus::ForValidation, $this->task->refresh()->status);
    }

    /**
     * @return array<string, array{PmStatus}>
     */
    public static function statusesThatCannotReport(): array
    {
        return [
            'scheduled' => [PmStatus::Scheduled],
            'for validation' => [PmStatus::ForValidation],
            'completed' => [PmStatus::Completed],
            'cancelled' => [PmStatus::Cancelled],
        ];
    }

    #[DataProvider('statusesThatCannotReport')]
    public function test_a_report_in_any_other_status_returns_422(PmStatus $status): void
    {
        $this->task->update(['status' => $status]);
        $this->actingAsVendorStaff();

        $this->submitReport($this->validReport())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['pm_task']);

        $this->assertDatabaseCount('pm_reports', 0);
        $this->assertEmpty(Storage::disk(config('pss.e_call_attachments.disk'))->allFiles());
    }

    public function test_a_missing_mandatory_item_returns_422_naming_the_item(): void
    {
        $this->actingAsVendorStaff();
        $responses = $this->validResponses();
        unset($responses[$this->engineOil->id]);

        $response = $this->submitReport($this->validReport(['responses' => $responses]));

        $this->assertErrorNamesItem($response, "responses.{$this->engineOil->id}.value", 'Engine oil inspection');
        $this->assertDatabaseCount('pm_reports', 0);
    }

    public function test_a_mandatory_photo_item_without_a_photo_returns_422_naming_the_item(): void
    {
        $this->actingAsVendorStaff();
        $responses = $this->validResponses();
        unset($responses[$this->areaPhoto->id]);

        $response = $this->submitReport($this->validReport(['responses' => $responses]));

        $this->assertErrorNamesItem($response, "responses.{$this->areaPhoto->id}.photos", 'Photo of the genset area');
    }

    public function test_a_failed_item_without_remarks_returns_422_naming_the_item(): void
    {
        $this->actingAsVendorStaff();
        $responses = $this->validResponses();
        $responses[$this->engineOil->id] = ['value' => 'fail'];

        $response = $this->submitReport($this->validReport(['responses' => $responses]));

        $this->assertErrorNamesItem($response, "responses.{$this->engineOil->id}.remarks", 'Engine oil inspection');
        $this->assertDatabaseCount('pm_reports', 0);
    }

    public function test_a_failed_item_with_remarks_is_accepted_and_stored_as_failed(): void
    {
        $this->actingAsVendorStaff();
        $responses = $this->validResponses();
        $responses[$this->engineOil->id] = ['value' => 'fail', 'remarks' => 'Oil is black; changed it.'];

        $this->submitReport($this->validReport(['responses' => $responses]))->assertCreated();

        $stored = PmReport::query()->sole()->responses()->where('checklist_item_id', $this->engineOil->id)->sole();
        $this->assertTrue($stored->is_failed);
        $this->assertSame('Oil is black; changed it.', $stored->remarks);
    }

    public function test_a_failed_item_that_does_not_require_remarks_is_accepted_without_them(): void
    {
        $this->actingAsVendorStaff();
        $responses = $this->validResponses();
        $responses[$this->alarm->id] = ['value' => 'no'];

        $this->submitReport($this->validReport(['responses' => $responses]))->assertCreated();

        $this->assertTrue(PmReport::query()->sole()->responses()->where('checklist_item_id', $this->alarm->id)->sole()->is_failed);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function outOfRangeReadings(): array
    {
        return [
            'below the min' => ['9.99'],
            'above the max' => ['20.51'],
        ];
    }

    #[DataProvider('outOfRangeReadings')]
    public function test_a_numeric_reading_outside_the_range_counts_as_failed(string $reading): void
    {
        $this->actingAsVendorStaff();
        $responses = $this->validResponses();
        $responses[$this->coolant->id] = ['value' => $reading];

        $response = $this->submitReport($this->validReport(['responses' => $responses]));
        $this->assertErrorNamesItem($response, "responses.{$this->coolant->id}.remarks", 'Coolant level');

        $responses = $this->validResponses();
        $responses[$this->coolant->id] = ['value' => $reading, 'remarks' => 'Topped up.'];
        $this->submitReport($this->validReport(['responses' => $responses]))->assertCreated();
        $this->assertTrue(PmReport::query()->sole()->responses()->where('checklist_item_id', $this->coolant->id)->sole()->is_failed);
    }

    public function test_a_reading_on_the_range_edge_is_not_failed(): void
    {
        $this->actingAsVendorStaff();
        $responses = $this->validResponses();
        $responses[$this->coolant->id] = ['value' => '20.5'];

        $this->submitReport($this->validReport(['responses' => $responses]))->assertCreated();

        $this->assertFalse(PmReport::query()->sole()->responses()->where('checklist_item_id', $this->coolant->id)->sole()->is_failed);
    }

    public function test_a_photo_required_item_without_a_photo_returns_422_naming_the_item(): void
    {
        $this->actingAsVendorStaff();
        $responses = $this->validResponses();
        $responses[$this->belt->id] = ['value' => 'good'];

        $response = $this->submitReport($this->validReport(['responses' => $responses]));

        $this->assertErrorNamesItem($response, "responses.{$this->belt->id}.photos", 'Belt condition');
    }

    public function test_a_general_attachment_does_not_satisfy_a_photo_required_item(): void
    {
        $this->actingAsVendorStaff();
        $responses = $this->validResponses();
        $responses[$this->belt->id] = ['value' => 'good'];

        $this->submitReport($this->validReport([
            'responses' => $responses,
            'attachments' => [UploadedFile::fake()->image('belt.jpg')],
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(["responses.{$this->belt->id}.photos"]);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function wrongTypeResponses(): array
    {
        return [
            'pass_fail answered good' => ['engineOil', 'good'],
            'numeric answered text' => ['coolant', 'abc'],
            'numeric with an exponent' => ['coolant', '1e2'],
            'yes_no answered pass' => ['alarm', 'pass'],
            'good_defective answered no' => ['belt', 'no'],
        ];
    }

    #[DataProvider('wrongTypeResponses')]
    public function test_a_response_of_the_wrong_type_returns_422(string $itemProperty, string $value): void
    {
        $this->actingAsVendorStaff();
        /** @var ChecklistItem $item */
        $item = $this->{$itemProperty};
        $responses = $this->validResponses();
        $responses[$item->id]['value'] = $value;

        $response = $this->submitReport($this->validReport(['responses' => $responses]));

        $this->assertErrorNamesItem($response, "responses.{$item->id}.value", $item->label);
    }

    public function test_a_photo_item_answered_with_a_value_returns_422(): void
    {
        $this->actingAsVendorStaff();
        $responses = $this->validResponses();
        $responses[$this->areaPhoto->id]['value'] = 'looks fine';

        $response = $this->submitReport($this->validReport(['responses' => $responses]));

        $this->assertErrorNamesItem($response, "responses.{$this->areaPhoto->id}.value", 'Photo of the genset area');
    }

    public function test_a_response_for_an_item_outside_the_tasks_checklist_returns_422(): void
    {
        $this->actingAsVendorStaff();
        $otherVersion = ChecklistTemplate::create(['key' => 'other', 'name' => 'Other', 'equipment_category_id' => 'CAT-X'])
            ->versions()->create(['version_number' => 1, 'content_fingerprint' => str_repeat('c', 64)]);
        $foreignItem = $otherVersion->items()->create(['position' => 1, 'label' => 'Foreign', 'response_type' => 'text']);

        $responses = $this->validResponses();
        $responses[$foreignItem->id] = ['value' => 'x'];

        $this->submitReport($this->validReport(['responses' => $responses]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['responses']);
    }

    public function test_a_photo_that_is_not_an_image_returns_422(): void
    {
        $this->actingAsVendorStaff();
        $responses = $this->validResponses();
        $responses[$this->belt->id]['photos'] = [UploadedFile::fake()->create('belt.pdf', 10, 'application/pdf')];

        $this->submitReport($this->validReport(['responses' => $responses]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(["responses.{$this->belt->id}.photos.0"]);
    }

    public function test_a_future_work_done_date_returns_422(): void
    {
        $this->actingAsVendorStaff();

        $this->submitReport($this->validReport(['work_done_date' => '2026-11-23']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['work_done_date' => 'The Work Done Date cannot be in the future.']);

        $this->submitReport($this->validReport(['work_done_date' => '2026-11-22']))->assertCreated();
    }

    /**
     * @return array<string, array{?string}>
     */
    public static function invalidWorkDoneDates(): array
    {
        return [
            'missing' => [null],
            'not a date' => ['yesterday-ish'],
            'wrong format' => ['20/11/2026'],
        ];
    }

    #[DataProvider('invalidWorkDoneDates')]
    public function test_an_invalid_work_done_date_returns_422(?string $date): void
    {
        $this->actingAsVendorStaff();

        $this->submitReport(array_filter($this->validReport(['work_done_date' => $date]), fn (mixed $value): bool => $value !== null))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['work_done_date']);
    }

    /**
     * @return array<string, array{array<string, string>, string}>
     */
    public static function invalidMaterials(): array
    {
        return [
            'zero quantity' => [['description' => 'Filter', 'quantity' => '0'], 'materials.0.quantity'],
            'negative quantity' => [['description' => 'Filter', 'quantity' => '-1'], 'materials.0.quantity'],
            'four decimals' => [['description' => 'Filter', 'quantity' => '1.2345'], 'materials.0.quantity'],
            'missing description' => [['quantity' => '1'], 'materials.0.description'],
        ];
    }

    /**
     * @param  array<string, string>  $material
     */
    #[DataProvider('invalidMaterials')]
    public function test_an_invalid_material_returns_422(array $material, string $field): void
    {
        $this->actingAsVendorStaff();

        $this->submitReport($this->validReport(['materials' => [$material]]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field]);
    }

    public function test_materials_attachments_and_findings_are_optional(): void
    {
        $this->actingAsVendorStaff();
        $report = $this->validReport();
        unset($report['materials'], $report['attachments'], $report['findings']);

        $this->submitReport($report)->assertCreated();
    }

    public function test_a_no_checklist_task_accepts_a_report_without_responses(): void
    {
        $task = PmTask::factory()->create(['vendor_id' => $this->vendor->id, 'status' => PmStatus::InProgress, 'no_checklist' => true]);
        $this->actingAsVendorStaff();
        $report = $this->validReport();
        unset($report['responses']);

        $this->submitReport($report, $task)
            ->assertCreated()
            ->assertJsonPath('data.checklist_template_version_id', null)
            ->assertJsonPath('data.responses', []);

        $this->assertSame(PmStatus::ForValidation, $task->refresh()->status);
    }

    public function test_a_no_checklist_task_rejects_responses(): void
    {
        $task = PmTask::factory()->create(['vendor_id' => $this->vendor->id, 'status' => PmStatus::InProgress, 'no_checklist' => true]);
        $this->actingAsVendorStaff();

        $this->submitReport($this->validReport(['responses' => [$this->notes->id => ['value' => 'x']]]), $task)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['responses']);
    }

    public function test_resubmitting_from_returned_replaces_the_current_responses_and_keeps_history(): void
    {
        $this->actingAsVendorStaff();
        $firstResponses = $this->validResponses();
        $firstResponses[$this->engineOil->id] = ['value' => 'fail', 'remarks' => 'Leak found.'];
        $this->submitReport($this->validReport(['responses' => $firstResponses, 'work_done_date' => '2026-11-18']))->assertCreated();

        $this->task->update(['status' => PmStatus::Returned]);

        $this->submitReport($this->validReport(['work_done_date' => '2026-11-21']))
            ->assertCreated()
            ->assertJsonPath('data.revision', 2)
            ->assertJsonPath('data.is_current', true);

        $this->task->refresh();
        $this->assertSame(PmStatus::ForValidation, $this->task->status);
        $this->assertSame('2026-11-21', $this->task->work_done_date->toDateString());

        $reports = $this->getJson("/api/pm-tasks/{$this->task->id}/reports")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.revision', 2)
            ->assertJsonPath('data.0.is_current', true)
            ->assertJsonPath('data.1.revision', 1)
            ->assertJsonPath('data.1.is_current', false)
            ->assertJsonPath('data.1.work_done_date', '2026-11-18')
            ->json('data');

        $oldEngineOil = collect($reports[1]['responses'])->firstWhere('checklist_item_id', $this->engineOil->id);
        $this->assertSame('fail', $oldEngineOil['value']);
        $this->assertSame('Leak found.', $oldEngineOil['remarks']);
        $this->assertTrue($oldEngineOil['is_failed']);

        $currentEngineOil = collect($reports[0]['responses'])->firstWhere('checklist_item_id', $this->engineOil->id);
        $this->assertSame('pass', $currentEngineOil['value']);
        $this->assertFalse($currentEngineOil['is_failed']);

        foreach (PmReport::query()->with('attachments')->get()->flatMap->attachments as $attachment) {
            Storage::disk(config('pss.e_call_attachments.disk'))->assertExists($attachment->path);
        }
    }

    public function test_a_submission_without_a_service_report_returns_422(): void
    {
        $this->actingAsVendorStaff();
        $report = $this->validReport();
        unset($report['service_report']);

        $this->submitReport($report)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['service_report']);

        $this->assertDatabaseCount('pm_reports', 0);
        $this->assertEmpty(Storage::disk(config('pss.e_call_attachments.disk'))->allFiles());
    }

    public function test_a_resubmission_from_returned_without_a_service_report_returns_422(): void
    {
        $this->actingAsVendorStaff();
        $this->submitReport($this->validReport())->assertCreated();
        $this->task->update(['status' => PmStatus::Returned]);
        $report = $this->validReport();
        unset($report['service_report']);

        $this->submitReport($report)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['service_report']);

        $this->assertSame(1, PmReport::query()->count());
        $this->assertSame(PmStatus::Returned, $this->task->refresh()->status);
    }

    /**
     * @return array<string, array{UploadedFile|string}>
     */
    public static function invalidServiceReports(): array
    {
        return [
            'disallowed type' => [UploadedFile::fake()->create('report.exe', 10, 'application/x-msdownload')],
            'over the size limit' => [UploadedFile::fake()->create('report.pdf', 10241, 'application/pdf')],
            'not a file' => ['report.pdf'],
        ];
    }

    #[DataProvider('invalidServiceReports')]
    public function test_an_invalid_service_report_returns_422(UploadedFile|string $serviceReport): void
    {
        $this->actingAsVendorStaff();

        $this->submitReport($this->validReport(['service_report' => $serviceReport]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['service_report']);

        $this->assertDatabaseCount('pm_reports', 0);
    }

    public function test_more_than_one_service_report_returns_422(): void
    {
        $this->actingAsVendorStaff();

        $this->submitReport($this->validReport(['service_report' => [
            UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'),
            UploadedFile::fake()->create('b.pdf', 10, 'application/pdf'),
        ]]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['service_report']);
    }

    public function test_each_revision_keeps_its_own_service_report(): void
    {
        $this->actingAsVendorStaff();
        $this->submitReport($this->validReport(['service_report' => UploadedFile::fake()->create('first.pdf', 10, 'application/pdf')]))->assertCreated();
        $this->task->update(['status' => PmStatus::Returned]);
        $this->submitReport($this->validReport(['service_report' => UploadedFile::fake()->create('second.docx', 10, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document')]))->assertCreated();

        $this->getJson("/api/pm-tasks/{$this->task->id}/reports")
            ->assertOk()
            ->assertJsonPath('data.0.service_report.original_name', 'second.docx')
            ->assertJsonPath('data.1.service_report.original_name', 'first.pdf');
    }

    private function startTaskAt(string $startedAt): void
    {
        $this->task->update(['status' => PmStatus::Scheduled]);
        Carbon::setTestNow($startedAt);
        $this->postJson("/api/pm-tasks/{$this->task->id}/start")->assertOk();
        Carbon::setTestNow('2026-11-22 10:00:00');
    }

    public function test_a_work_done_date_before_the_start_date_returns_422_with_a_clear_message(): void
    {
        $this->actingAsVendorStaff();
        $this->startTaskAt('2026-11-15 08:30:00');

        $this->submitReport($this->validReport(['work_done_date' => '2026-11-14']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['work_done_date' => 'The Work Done Date cannot be before the PM Task was started (2026-11-15).']);

        $this->assertDatabaseCount('pm_reports', 0);
    }

    public function test_the_start_date_itself_is_an_accepted_work_done_date(): void
    {
        $this->actingAsVendorStaff();
        $this->startTaskAt('2026-11-15 23:59:00');

        $this->submitReport($this->validReport(['work_done_date' => '2026-11-15']))->assertCreated();
    }

    public function test_today_is_an_accepted_work_done_date_after_a_start(): void
    {
        $this->actingAsVendorStaff();
        $this->startTaskAt('2026-11-15 08:30:00');

        $this->submitReport($this->validReport(['work_done_date' => '2026-11-22']))->assertCreated();
    }

    public function test_a_future_work_done_date_still_returns_422_after_a_start(): void
    {
        $this->actingAsVendorStaff();
        $this->startTaskAt('2026-11-15 08:30:00');

        $this->submitReport($this->validReport(['work_done_date' => '2026-11-23']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['work_done_date' => 'The Work Done Date cannot be in the future.']);
    }

    public function test_the_start_date_is_compared_in_the_app_timezone(): void
    {
        $this->assertSame('Asia/Manila', config('app.timezone'));
        $this->actingAsVendorStaff();
        $this->task->update(['status' => PmStatus::Scheduled]);
        Carbon::setTestNow(CarbonImmutable::parse('2026-11-14 17:00:00', 'UTC'));
        $this->postJson("/api/pm-tasks/{$this->task->id}/start")->assertOk();
        Carbon::setTestNow(CarbonImmutable::parse('2026-11-22 10:00:00', 'UTC'));

        $this->submitReport($this->validReport(['work_done_date' => '2026-11-14']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['work_done_date' => 'The Work Done Date cannot be before the PM Task was started (2026-11-15).']);
        $this->submitReport($this->validReport(['work_done_date' => '2026-11-15']))->assertCreated();
    }

    public function test_a_task_with_no_start_date_accepts_any_past_work_done_date(): void
    {
        $this->actingAsVendorStaff();
        $this->assertNull($this->task->started_at);

        $this->submitReport($this->validReport(['work_done_date' => '2020-01-01']))->assertCreated();
    }

    public function test_a_revision_stored_before_this_change_is_still_readable_unchanged(): void
    {
        $submitter = $this->actingAsVendorStaff();
        $legacy = $this->task->reports()->create([
            'revision' => 1,
            'checklist_template_version_id' => $this->checklist->id,
            'work_done_date' => '2026-01-05',
            'findings' => 'Filed before the service report was required.',
            'submitted_by_user_id' => $submitter->id,
            'submitted_at' => '2026-01-05 09:00:00',
        ]);
        $legacy->responses()->create(['checklist_item_id' => $this->engineOil->id, 'value' => 'pass', 'is_failed' => false]);
        $legacy->attachments()->create(['disk' => 'local', 'path' => 'legacy/report.pdf', 'original_name' => 'legacy-report.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 123]);
        $this->task->update(['status' => PmStatus::ForValidation, 'work_done_date' => '2026-01-05', 'started_at' => '2026-03-01 08:00:00']);

        $this->getJson("/api/pm-tasks/{$this->task->id}/reports")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.revision', 1)
            ->assertJsonPath('data.0.work_done_date', '2026-01-05')
            ->assertJsonPath('data.0.findings', 'Filed before the service report was required.')
            ->assertJsonPath('data.0.service_report', null)
            ->assertJsonPath('data.0.responses.0.value', 'pass')
            ->assertJsonPath('data.0.attachments.0.original_name', 'legacy-report.pdf');

        $this->assertSame('2026-01-05', $legacy->refresh()->work_done_date->toDateString());
        $this->assertNull($legacy->service_report_path);
    }

    public function test_another_vendor_cannot_read_the_reports(): void
    {
        $this->actingAsVendorStaff();
        $this->submitReport($this->validReport())->assertCreated();

        $this->actingAsVendorStaff(Vendor::factory()->create());

        $this->getJson("/api/pm-tasks/{$this->task->id}/reports")->assertForbidden();
    }

    public function test_non_vendor_users_can_read_the_reports(): void
    {
        $this->actingAsVendorStaff();
        $this->submitReport($this->validReport())->assertCreated();

        $this->actingAs(User::factory()->create());

        $this->getJson("/api/pm-tasks/{$this->task->id}/reports")->assertOk()->assertJsonCount(1, 'data');
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function allEndpoints(): array
    {
        return [
            'list' => ['get', '/api/vendor/pm-tasks'],
            'start' => ['post', '/api/pm-tasks/1/start'],
            'report' => ['post', '/api/pm-tasks/1/report'],
            'reports' => ['get', '/api/pm-tasks/1/reports'],
        ];
    }

    #[DataProvider('allEndpoints')]
    public function test_every_endpoint_returns_401_when_unauthenticated(string $method, string $uri): void
    {
        $this->json($method, $uri)->assertUnauthorized();
    }
}
