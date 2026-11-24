<?php

namespace Tests\Feature;

use App\Enums\ECallPriority;
use App\Enums\ECallStatus;
use App\Http\Requests\StoreECallRequest;
use App\MasterData\Data\Employee;
use App\MasterData\Data\Equipment;
use App\MasterData\Data\EquipmentCategory;
use App\MasterData\Data\Site;
use App\MasterData\InMemoryMasterDataDirectory;
use App\MasterData\MasterDataDirectory;
use App\Models\ECall;
use App\Models\SiteSetting;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ECallApiTest extends TestCase
{
    use RefreshDatabase;

    private InMemoryMasterDataDirectory $directory;

    private Vendor $makatiVendor;

    private Vendor $cebuVendor;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('pss.e_call_attachments.disk'));

        $this->directory = $this->app->make(MasterDataDirectory::class);
        $this->directory
            ->addSite(new Site(id: 'S-1', name: 'Makati Office'))
            ->addSite(new Site(id: 'S-2', name: 'Cebu Site'))
            ->addSite(new Site(id: 'S-NO-VENDOR', name: 'Davao Site'))
            ->addSite(new Site(id: 'S-NO-EIC', name: 'Iloilo Site'))
            ->addEquipmentCategory(new EquipmentCategory(id: 'CAT-AC', name: 'Aircon'))
            ->addEquipment(new Equipment(id: 'EQ-1', siteId: 'S-1', name: 'Lobby Aircon', categoryId: 'CAT-AC', location: '2F Lobby'))
            ->addEquipment(new Equipment(id: 'EQ-2', siteId: 'S-2', name: 'Cebu Genset', categoryId: 'CAT-AC'))
            ->addEmployee(new Employee(id: 'P-1', name: 'Erwin Engineer'))
            ->addEmployee(new Employee(id: 'P-2', name: 'Ella Engineer'));

        $this->makatiVendor = Vendor::factory()->create(['name' => 'Acme Facilities']);
        $this->cebuVendor = Vendor::factory()->create(['name' => 'Cebu Services']);

        SiteSetting::factory()->create(['site_id' => 'S-1', 'site_name_snapshot' => 'Makati Office', 'vendor_id' => $this->makatiVendor->id, 'eic_employee_id' => 'P-1']);
        SiteSetting::factory()->create(['site_id' => 'S-2', 'site_name_snapshot' => 'Cebu Site', 'vendor_id' => $this->cebuVendor->id, 'eic_employee_id' => 'P-2']);
        SiteSetting::factory()->create(['site_id' => 'S-NO-VENDOR', 'site_name_snapshot' => 'Davao Site', 'vendor_id' => null, 'eic_employee_id' => 'P-1']);
        SiteSetting::factory()->create(['site_id' => 'S-NO-EIC', 'site_name_snapshot' => 'Iloilo Site', 'vendor_id' => $this->makatiVendor->id, 'eic_employee_id' => null]);
    }

    private function actingAsRequestor(): User
    {
        $user = User::factory()->create(['employee_id' => 'P-100']);
        $this->actingAs($user);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'site_id' => 'S-1',
            'nature_of_issue' => 'Civil works',
            'description' => 'Build a new fence along the parking lot.',
            'priority' => 'normal',
        ], $overrides);
    }

    public function test_a_valid_e_call_with_no_equipment_is_accepted(): void
    {
        $requestor = $this->actingAsRequestor();

        $response = $this->postJson('/api/e-calls', $this->validPayload())
            ->assertCreated()
            ->assertJsonPath('data.site.id', 'S-1')
            ->assertJsonPath('data.site.name', 'Makati Office')
            ->assertJsonPath('data.equipment', null)
            ->assertJsonPath('data.nature_of_issue', 'Civil works')
            ->assertJsonPath('data.description', 'Build a new fence along the parking lot.')
            ->assertJsonPath('data.priority', 'normal')
            ->assertJsonPath('data.status', 'for_vendor_assessment')
            ->assertJsonPath('data.vendor.id', $this->makatiVendor->id)
            ->assertJsonPath('data.eic_employee_id', 'P-1')
            ->assertJsonPath('data.requestor.user_id', $requestor->id)
            ->assertJsonPath('data.requestor.employee_id', 'P-100')
            ->assertJsonPath('data.pm_task_id', null)
            ->assertJsonPath('data.attachments', []);

        $eCall = ECall::query()->findOrFail($response->json('data.id'));
        $this->assertSame(ECallStatus::ForVendorAssessment, $eCall->status);
        $this->assertSame(ECallPriority::Normal, $eCall->priority);
        $this->assertNull($eCall->equipment_id);
        $this->assertNull($eCall->equipment_name_snapshot);
    }

    public function test_the_e_call_stores_the_requestor_routing_snapshots_and_attachments(): void
    {
        $requestor = $this->actingAsRequestor();

        $response = $this->post('/api/e-calls', $this->validPayload([
            'equipment_id' => 'EQ-1',
            'priority' => 'emergency',
            'attachments' => [
                UploadedFile::fake()->image('leak.jpg'),
                UploadedFile::fake()->create('quote.pdf', 200, 'application/pdf'),
                UploadedFile::fake()->create('specs.docx', 50, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'),
            ],
        ]), ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.equipment.id', 'EQ-1')
            ->assertJsonPath('data.equipment.name', 'Lobby Aircon')
            ->assertJsonPath('data.equipment.category', 'Aircon')
            ->assertJsonPath('data.equipment.location', '2F Lobby')
            ->assertJsonCount(3, 'data.attachments')
            ->assertJsonPath('data.attachments.1.original_name', 'quote.pdf');

        $eCall = ECall::query()->with('attachments')->findOrFail($response->json('data.id'));
        $this->assertSame($requestor->id, $eCall->requestor_user_id);
        $this->assertSame('P-100', $eCall->requestor_employee_id);
        $this->assertSame($this->makatiVendor->id, $eCall->vendor_id);
        $this->assertSame('P-1', $eCall->eic_employee_id);
        $this->assertSame('Makati Office', $eCall->site_name_snapshot);
        $this->assertSame('EQ-1', $eCall->equipment_id);
        $this->assertSame('Lobby Aircon', $eCall->equipment_name_snapshot);
        $this->assertSame('CAT-AC', $eCall->equipment_category_id);
        $this->assertSame('Aircon', $eCall->equipment_category_snapshot);
        $this->assertSame('2F Lobby', $eCall->equipment_location_snapshot);
        $this->assertSame(ECallPriority::Emergency, $eCall->priority);

        $this->assertCount(3, $eCall->attachments);
        foreach ($eCall->attachments as $attachment) {
            Storage::disk(config('pss.e_call_attachments.disk'))->assertExists($attachment->path);
        }
        $this->assertSame(['leak.jpg', 'quote.pdf', 'specs.docx'], $eCall->attachments->pluck('original_name')->all());
    }

    public function test_filing_an_e_call_for_inactive_equipment_returns_422(): void
    {
        $this->directory->addEquipment(new Equipment(id: 'EQ-1', siteId: 'S-1', name: 'Lobby Aircon', categoryId: 'CAT-AC', isActive: false));
        $this->actingAsRequestor();

        $this->postJson('/api/e-calls', $this->validPayload(['equipment_id' => 'EQ-1']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['equipment_id' => StoreECallRequest::INACTIVE_EQUIPMENT_MESSAGE]);

        $this->assertDatabaseCount('e_calls', 0);
    }

    public function test_an_e_call_still_reads_its_snapshots_after_its_equipment_goes_inactive(): void
    {
        $this->actingAsRequestor();
        $id = $this->postJson('/api/e-calls', $this->validPayload(['equipment_id' => 'EQ-1']))->assertCreated()->json('data.id');

        $this->directory->addEquipment(new Equipment(id: 'EQ-1', siteId: 'S-1', name: 'Renamed Aircon', categoryId: 'CAT-AC', isActive: false));

        $this->getJson("/api/e-calls/{$id}")
            ->assertOk()
            ->assertJsonPath('data.site.name', 'Makati Office')
            ->assertJsonPath('data.equipment.name', 'Lobby Aircon');
    }

    public function test_the_routing_and_snapshots_are_kept_after_the_site_changes(): void
    {
        $this->actingAsRequestor();
        $id = $this->postJson('/api/e-calls', $this->validPayload(['equipment_id' => 'EQ-1']))->assertCreated()->json('data.id');

        SiteSetting::query()->where('site_id', 'S-1')->update(['vendor_id' => $this->cebuVendor->id, 'eic_employee_id' => 'P-2']);
        $this->app->instance(MasterDataDirectory::class, new InMemoryMasterDataDirectory);

        $this->getJson("/api/e-calls/{$id}")
            ->assertOk()
            ->assertJsonPath('data.vendor.id', $this->makatiVendor->id)
            ->assertJsonPath('data.eic_employee_id', 'P-1')
            ->assertJsonPath('data.site.name', 'Makati Office')
            ->assertJsonPath('data.equipment.name', 'Lobby Aircon');
    }

    public function test_the_generic_endpoint_refuses_a_pm_task_reference(): void
    {
        $this->actingAsRequestor();

        $this->postJson('/api/e-calls', $this->validPayload(['pm_task_id' => 42]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['pm_task_id' => 'prohibited']);

        $this->assertDatabaseCount('e_calls', 0);
    }

    public function test_an_e_call_filed_through_the_generic_endpoint_has_no_pm_task(): void
    {
        $this->actingAsRequestor();

        $this->postJson('/api/e-calls', $this->validPayload())
            ->assertCreated()
            ->assertJsonPath('data.pm_task_id', null);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidPayloads(): array
    {
        return [
            'equipment at another site' => [['equipment_id' => 'EQ-2'], 'equipment_id'],
            'unknown equipment' => [['equipment_id' => 'EQ-unknown'], 'equipment_id'],
            'unknown site' => [['site_id' => 'S-unknown'], 'site_id'],
            'missing site' => [['site_id' => null], 'site_id'],
            'priority outside the four values' => [['priority' => 'urgent'], 'priority'],
            'missing priority' => [['priority' => null], 'priority'],
            'missing nature of issue' => [['nature_of_issue' => null], 'nature_of_issue'],
            'missing description' => [['description' => null], 'description'],
            'pm task reference of any kind' => [['pm_task_id' => 'abc'], 'pm_task_id'],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    #[DataProvider('invalidPayloads')]
    public function test_an_invalid_e_call_returns_422(array $overrides, string $field): void
    {
        $this->actingAsRequestor();

        $this->postJson('/api/e-calls', $this->validPayload($overrides))
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field]);

        $this->assertDatabaseCount('e_calls', 0);
    }

    public function test_all_four_priorities_are_accepted(): void
    {
        $this->actingAsRequestor();

        foreach (['emergency', 'high', 'normal', 'low'] as $priority) {
            $this->postJson('/api/e-calls', $this->validPayload(['priority' => $priority]))
                ->assertCreated()
                ->assertJsonPath('data.priority', $priority);
        }
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function sitesMissingRouting(): array
    {
        return [
            'no site vendor' => ['S-NO-VENDOR', 'This Site has no Site Vendor assigned, so the E-Call cannot be routed. Ask an administrator to assign one.'],
            'no eic' => ['S-NO-EIC', 'This Site has no EIC assigned, so the E-Call cannot be routed. Ask an administrator to assign one.'],
            'no site settings at all' => ['S-2-UNSET', 'This Site has no Site Vendor assigned, so the E-Call cannot be routed. Ask an administrator to assign one.'],
        ];
    }

    #[DataProvider('sitesMissingRouting')]
    public function test_a_site_without_a_site_vendor_or_eic_returns_422_with_a_clear_message(string $siteId, string $message): void
    {
        $this->directory->addSite(new Site(id: 'S-2-UNSET', name: 'Baguio Site'));
        $this->actingAsRequestor();

        $this->postJson('/api/e-calls', $this->validPayload(['site_id' => $siteId]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['site_id' => $message]);

        $this->assertDatabaseCount('e_calls', 0);
    }

    /**
     * @return array<string, array{UploadedFile}>
     */
    public static function invalidAttachments(): array
    {
        return [
            'executable' => [UploadedFile::fake()->create('virus.exe', 10, 'application/x-msdownload')],
            'too large' => [UploadedFile::fake()->create('huge.pdf', 10241, 'application/pdf')],
        ];
    }

    #[DataProvider('invalidAttachments')]
    public function test_an_invalid_attachment_returns_422_and_stores_nothing(UploadedFile $file): void
    {
        $this->actingAsRequestor();

        $this->post('/api/e-calls', $this->validPayload(['attachments' => [$file]]), ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['attachments.0']);

        $this->assertDatabaseCount('e_calls', 0);
        $this->assertEmpty(Storage::disk(config('pss.e_call_attachments.disk'))->allFiles());
    }

    public function test_the_attachment_size_limit_is_configurable(): void
    {
        config(['pss.e_call_attachments.max_kilobytes' => 100]);
        $this->actingAsRequestor();

        $this->post('/api/e-calls', $this->validPayload([
            'attachments' => [UploadedFile::fake()->create('photo.pdf', 101, 'application/pdf')],
        ]), ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['attachments.0']);
    }

    public function test_the_default_attachment_limit_is_ten_megabytes(): void
    {
        $this->assertSame(10240, config('pss.e_call_attachments.max_kilobytes'));
    }

    public function test_show_returns_the_e_call(): void
    {
        $this->actingAsRequestor();
        $id = $this->postJson('/api/e-calls', $this->validPayload())->json('data.id');

        $this->getJson("/api/e-calls/{$id}")
            ->assertOk()
            ->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.status', 'for_vendor_assessment');
    }

    public function test_show_returns_404_for_an_unknown_e_call(): void
    {
        $this->actingAsRequestor();

        $this->getJson('/api/e-calls/9999')->assertNotFound();
    }

    /**
     * @return list<int>
     */
    private function fileOneECallPerSite(): array
    {
        $this->actingAsRequestor();

        return [
            $this->postJson('/api/e-calls', $this->validPayload(['site_id' => 'S-1', 'priority' => 'high']))->assertCreated()->json('data.id'),
            $this->postJson('/api/e-calls', $this->validPayload(['site_id' => 'S-2', 'priority' => 'low']))->assertCreated()->json('data.id'),
        ];
    }

    public function test_list_returns_every_e_call_to_a_non_vendor_user(): void
    {
        [$makatiId, $cebuId] = $this->fileOneECallPerSite();

        $this->actingAs(User::factory()->create());

        $ids = collect($this->getJson('/api/e-calls')->assertOk()->json('data'))->pluck('id')->sort()->values()->all();
        $this->assertSame([$makatiId, $cebuId], $ids);
    }

    public function test_vendor_staff_list_only_their_vendors_e_calls(): void
    {
        [$makatiId] = $this->fileOneECallPerSite();

        $this->actingAs(User::factory()->create(['vendor_id' => $this->makatiVendor->id]));

        $this->getJson('/api/e-calls')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $makatiId);
    }

    public function test_vendor_staff_get_403_on_another_vendors_e_call(): void
    {
        [$makatiId, $cebuId] = $this->fileOneECallPerSite();

        $this->actingAs(User::factory()->create(['vendor_id' => $this->makatiVendor->id]));

        $this->getJson("/api/e-calls/{$cebuId}")->assertForbidden();
        $this->getJson("/api/e-calls/{$makatiId}")->assertOk();
    }

    public function test_vendor_scoping_follows_the_vendor_captured_at_filing_time(): void
    {
        [$makatiId] = $this->fileOneECallPerSite();
        SiteSetting::query()->where('site_id', 'S-1')->update(['vendor_id' => $this->cebuVendor->id]);

        $this->actingAs(User::factory()->create(['vendor_id' => $this->cebuVendor->id]));

        $this->getJson("/api/e-calls/{$makatiId}")->assertForbidden();
        $this->assertNotContains($makatiId, collect($this->getJson('/api/e-calls')->json('data'))->pluck('id')->all());
    }

    /**
     * @return array<string, array{array<string, string>, int}>
     */
    public static function filters(): array
    {
        return [
            'site' => [['site_id' => 'S-2'], 1],
            'priority' => [['priority' => 'high'], 0],
            'status' => [['status' => 'for_vendor_assessment'], 2],
            'status with no matches' => [['status' => 'approved'], -1],
        ];
    }

    /**
     * @param  array<string, string>  $query
     */
    #[DataProvider('filters')]
    public function test_list_can_be_filtered(array $query, int $expectedIndex): void
    {
        $ids = $this->fileOneECallPerSite();

        $expected = match ($expectedIndex) {
            -1 => [],
            2 => $ids,
            default => [$ids[$expectedIndex]],
        };

        $actual = collect($this->getJson('/api/e-calls?'.http_build_query($query))->assertOk()->json('data'))->pluck('id')->sort()->values()->all();
        $this->assertSame($expected, $actual);
    }

    /**
     * @return array<string, array{array<string, string>}>
     */
    public static function invalidFilters(): array
    {
        return [
            'unknown status' => [['status' => 'nope']],
            'unknown priority' => [['priority' => 'urgent']],
        ];
    }

    /**
     * @param  array<string, string>  $query
     */
    #[DataProvider('invalidFilters')]
    public function test_an_invalid_filter_returns_422(array $query): void
    {
        $this->actingAsRequestor();

        $this->getJson('/api/e-calls?'.http_build_query($query))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(array_keys($query));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function allEndpoints(): array
    {
        return [
            'list' => ['get', '/api/e-calls'],
            'show' => ['get', '/api/e-calls/1'],
            'file' => ['post', '/api/e-calls'],
        ];
    }

    #[DataProvider('allEndpoints')]
    public function test_every_endpoint_returns_401_when_unauthenticated(string $method, string $uri): void
    {
        $this->json($method, $uri, $this->validPayload())->assertUnauthorized();

        $this->assertDatabaseCount('e_calls', 0);
    }

    public function test_users_carry_nullable_employee_and_vendor_ids(): void
    {
        $plainUser = User::factory()->create();
        $vendorStaff = User::factory()->create(['employee_id' => 'P-200', 'vendor_id' => $this->makatiVendor->id]);

        $this->assertNull($plainUser->employee_id);
        $this->assertNull($plainUser->vendor_id);
        $this->assertSame('P-200', $vendorStaff->refresh()->employee_id);
        $this->assertSame($this->makatiVendor->id, $vendorStaff->vendor_id);
        $this->assertTrue($vendorStaff->vendor->is($this->makatiVendor));
    }
}
