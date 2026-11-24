<?php

namespace Tests\Feature;

use App\Enums\ECallStatus;
use App\Enums\PmStatus;
use App\Http\Requests\StoreECallRequest;
use App\MasterData\Data\Equipment;
use App\MasterData\Data\EquipmentCategory;
use App\MasterData\Data\Site;
use App\MasterData\InMemoryMasterDataDirectory;
use App\MasterData\MasterDataDirectory;
use App\Models\ApprovalRecord;
use App\Models\ECall;
use App\Models\PmTask;
use App\Models\SiteSetting;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Issue 13: the Site's current EIC files an E-Call from a PM Task's findings without changing the task.
 */
class PmTaskECallApiTest extends TestCase
{
    use RefreshDatabase;

    private InMemoryMasterDataDirectory $directory;

    private Vendor $vendor;

    private PmTask $task;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-23 10:00:00');
        Storage::fake(config('pss.e_call_attachments.disk'));

        $this->directory = $this->app->make(MasterDataDirectory::class);
        $this->directory
            ->addSite(new Site(id: 'S-1', name: 'Makati Office (renamed)'))
            ->addEquipmentCategory(new EquipmentCategory(id: 'CAT-AC', name: 'Aircon'))
            ->addEquipment(new Equipment(id: 'EQ-1', siteId: 'S-1', name: 'Lobby Aircon (current)', categoryId: 'CAT-AC', location: '2F Lobby'))
            ->addEquipment(new Equipment(id: 'EQ-3', siteId: 'S-1', name: 'Server Room Aircon', categoryId: 'CAT-AC'));

        $this->vendor = Vendor::factory()->create();
        SiteSetting::factory()->create(['site_id' => 'S-1', 'vendor_id' => $this->vendor->id, 'eic_employee_id' => 'P-EIC']);
        $this->task = PmTask::factory()->create([
            'site_id' => 'S-1',
            'site_name_snapshot' => 'Makati Office (old)',
            'equipment_id' => 'EQ-1',
            'equipment_name_snapshot' => 'Lobby Aircon (old)',
            'vendor_id' => $this->vendor->id,
            'eic_employee_id' => 'P-EIC',
            'status' => PmStatus::ForValidation,
            'work_done_date' => '2026-11-20',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function actingAsEic(): User
    {
        $eic = User::factory()->create(['employee_id' => 'P-EIC']);
        $this->actingAs($eic);

        return $eic;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'nature_of_issue' => 'Compressor noise',
            'description' => 'PM finding: compressor is noisy, needs replacement.',
            'priority' => 'high',
        ], $overrides);
    }

    private function url(?PmTask $task = null): string
    {
        return '/api/pm-tasks/'.($task ?? $this->task)->id.'/e-calls';
    }

    public function test_the_eic_files_an_e_call_prefilled_from_the_pm_task(): void
    {
        $eic = $this->actingAsEic();

        $response = $this->post($this->url(), $this->payload([
            'attachments' => [UploadedFile::fake()->image('finding.jpg')],
        ]), ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.pm_task_id', $this->task->id)
            ->assertJsonPath('data.status', ECallStatus::ForVendorAssessment->value)
            ->assertJsonPath('data.site.id', 'S-1')
            ->assertJsonPath('data.equipment.id', 'EQ-1')
            ->assertJsonPath('data.vendor.id', $this->vendor->id)
            ->assertJsonPath('data.eic_employee_id', 'P-EIC')
            ->assertJsonPath('data.priority', 'high')
            ->assertJsonPath('data.nature_of_issue', 'Compressor noise')
            ->assertJsonCount(1, 'data.attachments');

        $eCall = ECall::query()->findOrFail($response->json('data.id'));
        $this->assertSame($eic->id, $eCall->requestor_user_id);
        $this->assertSame('P-EIC', $eCall->requestor_employee_id);
        // Snapshots are re-read from the directory at filing time, not copied from the PM Task.
        $this->assertSame('Makati Office (renamed)', $eCall->site_name_snapshot);
        $this->assertSame('Lobby Aircon (current)', $eCall->equipment_name_snapshot);
        $this->assertSame('Aircon', $eCall->equipment_category_snapshot);
        $this->assertSame('2F Lobby', $eCall->equipment_location_snapshot);
    }

    public function test_routing_uses_the_sites_current_vendor_not_the_pm_tasks_vendor(): void
    {
        $newVendor = Vendor::factory()->create();
        SiteSetting::query()->where('site_id', 'S-1')->update(['vendor_id' => $newVendor->id]);
        $this->actingAsEic();

        $this->postJson($this->url(), $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.vendor.id', $newVendor->id);
    }

    /**
     * @return array<string, array{PmStatus}>
     */
    public static function reviewableStatuses(): array
    {
        return [
            'for validation' => [PmStatus::ForValidation],
            'returned' => [PmStatus::Returned],
        ];
    }

    #[DataProvider('reviewableStatuses')]
    public function test_filing_does_not_change_the_pm_task(PmStatus $status): void
    {
        $this->task->update(['status' => $status]);
        $this->actingAsEic();

        $this->postJson($this->url(), $this->payload())->assertCreated();

        $this->task->refresh();
        $this->assertSame($status, $this->task->status);
        $this->assertSame('2026-11-20', $this->task->work_done_date->toDateString());
        $this->assertSame(0, ApprovalRecord::query()->count(), 'No Approval Record is written.');
    }

    /**
     * @return array<string, array{PmStatus}>
     */
    public static function nonReviewableStatuses(): array
    {
        return [
            'scheduled' => [PmStatus::Scheduled],
            'in progress' => [PmStatus::InProgress],
            'completed' => [PmStatus::Completed],
            'cancelled' => [PmStatus::Cancelled],
        ];
    }

    #[DataProvider('nonReviewableStatuses')]
    public function test_filing_from_a_task_outside_review_returns_422(PmStatus $status): void
    {
        $this->task->update(['status' => $status]);
        $this->actingAsEic();

        $this->postJson($this->url(), $this->payload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['pm_task' => 'An E-Call can only be filed from a PM Task that is For Validation or Returned.']);

        $this->assertDatabaseCount('e_calls', 0);
    }

    public function test_a_replaced_eic_gets_403(): void
    {
        $this->actingAsEic();
        SiteSetting::query()->where('site_id', 'S-1')->update(['eic_employee_id' => 'P-NEW']);

        $this->postJson($this->url(), $this->payload())->assertForbidden();

        $this->assertDatabaseCount('e_calls', 0);
    }

    public function test_the_current_eic_who_replaced_the_tasks_eic_can_file(): void
    {
        SiteSetting::query()->where('site_id', 'S-1')->update(['eic_employee_id' => 'P-NEW']);
        $this->actingAs(User::factory()->create(['employee_id' => 'P-NEW']));

        $this->postJson($this->url(), $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.eic_employee_id', 'P-NEW');
    }

    public function test_vendor_staff_get_403(): void
    {
        $this->actingAs(User::factory()->create(['employee_id' => null, 'vendor_id' => $this->vendor->id]));

        $this->postJson($this->url(), $this->payload())->assertForbidden();

        $this->assertDatabaseCount('e_calls', 0);
    }

    public function test_another_employee_gets_403(): void
    {
        $this->actingAs(User::factory()->create(['employee_id' => 'P-OTHER']));

        $this->postJson($this->url(), $this->payload())->assertForbidden();

        $this->assertDatabaseCount('e_calls', 0);
    }

    public function test_a_guest_gets_401(): void
    {
        $this->postJson($this->url(), $this->payload())->assertUnauthorized();
    }

    public function test_equipment_no_longer_at_the_site_returns_422(): void
    {
        $this->directory->addEquipment(new Equipment(id: 'EQ-1', siteId: 'S-2', name: 'Moved Aircon', categoryId: 'CAT-AC'));
        $this->actingAsEic();

        $this->postJson($this->url(), $this->payload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['equipment' => "The PM Task's Equipment is no longer at its Site in the equipment directory."]);

        $this->assertDatabaseCount('e_calls', 0);
    }

    public function test_inactive_equipment_returns_422(): void
    {
        $this->directory->addEquipment(new Equipment(id: 'EQ-1', siteId: 'S-1', name: 'Lobby Aircon', categoryId: 'CAT-AC', isActive: false));
        $this->actingAsEic();

        $this->postJson($this->url(), $this->payload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['equipment' => StoreECallRequest::INACTIVE_EQUIPMENT_MESSAGE]);

        $this->assertDatabaseCount('e_calls', 0);
    }

    public function test_equipment_missing_from_the_directory_returns_422(): void
    {
        $this->app->instance(MasterDataDirectory::class, (new InMemoryMasterDataDirectory)
            ->addSite(new Site(id: 'S-1', name: 'Makati Office')));
        $this->actingAsEic();

        $this->postJson($this->url(), $this->payload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['equipment']);

        $this->assertDatabaseCount('e_calls', 0);
    }

    public function test_a_site_missing_from_the_directory_returns_422(): void
    {
        $this->app->instance(MasterDataDirectory::class, new InMemoryMasterDataDirectory);
        $this->actingAsEic();

        $this->postJson($this->url(), $this->payload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['site' => 'The PM Task\'s Site is not known to the site directory.']);

        $this->assertDatabaseCount('e_calls', 0);
    }

    public function test_a_site_without_a_site_vendor_returns_422(): void
    {
        SiteSetting::query()->where('site_id', 'S-1')->update(['vendor_id' => null]);
        $this->actingAsEic();

        $this->postJson($this->url(), $this->payload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['site' => 'This Site has no Site Vendor assigned, so the E-Call cannot be routed. Ask an administrator to assign one.']);

        $this->assertDatabaseCount('e_calls', 0);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidPayloads(): array
    {
        return [
            'missing nature of issue' => [['nature_of_issue' => null], 'nature_of_issue'],
            'missing description' => [['description' => null], 'description'],
            'missing priority' => [['priority' => null], 'priority'],
            'priority outside the four values' => [['priority' => 'urgent'], 'priority'],
            'disallowed attachment type' => [['attachments' => [UploadedFile::fake()->create('virus.exe', 10)]], 'attachments.0'],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    #[DataProvider('invalidPayloads')]
    public function test_an_invalid_payload_returns_422(array $overrides, string $field): void
    {
        $this->actingAsEic();

        $this->post($this->url(), $this->payload($overrides), ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field]);

        $this->assertDatabaseCount('e_calls', 0);
    }

    public function test_too_many_attachments_returns_422(): void
    {
        config(['pss.e_call_attachments.max_files' => 1]);
        $this->actingAsEic();

        $this->post($this->url(), $this->payload([
            'attachments' => [UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.jpg')],
        ]), ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['attachments']);
    }

    public function test_show_lists_the_e_calls_filed_from_the_task_oldest_first(): void
    {
        $this->actingAsEic();
        $first = $this->postJson($this->url(), $this->payload(['nature_of_issue' => 'First']))->json('data.id');
        $second = $this->postJson($this->url(), $this->payload(['nature_of_issue' => 'Second', 'priority' => 'low']))->json('data.id');
        $otherTask = PmTask::factory()->create(['site_id' => 'S-1', 'equipment_id' => 'EQ-3', 'vendor_id' => $this->vendor->id, 'status' => PmStatus::Returned]);
        $this->postJson($this->url($otherTask), $this->payload(['nature_of_issue' => 'Other task']))->assertCreated();
        ECall::factory()->create(['site_id' => 'S-1', 'vendor_id' => $this->vendor->id]);

        $this->getJson("/api/pm-tasks/{$this->task->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $this->task->id)
            ->assertJsonPath('data.status', 'for_validation')
            ->assertJsonPath('data.equipment.id', 'EQ-1')
            ->assertJsonCount(2, 'data.e_calls')
            ->assertJsonPath('data.e_calls.0.id', $first)
            ->assertJsonPath('data.e_calls.0.nature_of_issue', 'First')
            ->assertJsonPath('data.e_calls.0.status', ECallStatus::ForVendorAssessment->value)
            ->assertJsonPath('data.e_calls.0.priority', 'high')
            ->assertJsonPath('data.e_calls.1.id', $second)
            ->assertJsonPath('data.e_calls.1.priority', 'low')
            ->assertJsonStructure(['data' => ['timeliness', 'e_calls' => [['id', 'status', 'priority', 'nature_of_issue', 'created_at']]]]);
    }

    public function test_show_returns_an_empty_e_call_list_when_none_were_filed(): void
    {
        $this->actingAsEic();

        $this->getJson("/api/pm-tasks/{$this->task->id}")
            ->assertOk()
            ->assertJsonPath('data.e_calls', []);
    }

    public function test_the_tasks_vendor_staff_can_view_it(): void
    {
        $this->actingAs(User::factory()->create(['employee_id' => null, 'vendor_id' => $this->vendor->id]));

        $this->getJson("/api/pm-tasks/{$this->task->id}")->assertOk();
    }

    public function test_another_vendors_staff_cannot_view_the_task(): void
    {
        $this->actingAs(User::factory()->create(['employee_id' => null, 'vendor_id' => Vendor::factory()->create()->id]));

        $this->getJson("/api/pm-tasks/{$this->task->id}")->assertForbidden();
    }

    public function test_vendor_staff_only_see_e_calls_routed_to_their_vendor(): void
    {
        $this->actingAsEic();
        $this->postJson($this->url(), $this->payload(['nature_of_issue' => 'Routed to the task vendor']))->assertCreated();
        $newVendor = Vendor::factory()->create();
        SiteSetting::query()->where('site_id', 'S-1')->update(['vendor_id' => $newVendor->id]);
        $this->postJson($this->url(), $this->payload(['nature_of_issue' => 'Routed to the new vendor']))->assertCreated();

        $this->actingAs(User::factory()->create(['employee_id' => null, 'vendor_id' => $this->vendor->id]));

        $this->getJson("/api/pm-tasks/{$this->task->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data.e_calls')
            ->assertJsonPath('data.e_calls.0.nature_of_issue', 'Routed to the task vendor');
    }

    public function test_deleting_a_referenced_pm_task_is_blocked_by_the_foreign_key(): void
    {
        $this->actingAsEic();
        $this->postJson($this->url(), $this->payload())->assertCreated();

        $this->expectException(QueryException::class);

        $this->task->delete();
    }
}
