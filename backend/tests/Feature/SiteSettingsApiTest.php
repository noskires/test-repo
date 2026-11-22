<?php

namespace Tests\Feature;

use App\Enums\PmFrequency;
use App\Enums\SiteAssignment;
use App\Events\SitePmFrequencyChanged;
use App\MasterData\Data\Employee;
use App\MasterData\Data\Site;
use App\MasterData\InMemoryMasterDataDirectory;
use App\MasterData\MasterDataDirectory;
use App\Models\SiteAssignmentChange;
use App\Models\SiteSetting;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SiteSettingsApiTest extends TestCase
{
    use RefreshDatabase;

    private InMemoryMasterDataDirectory $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = $this->app->make(MasterDataDirectory::class);
        $this->directory
            ->addSite(new Site(id: 'S-1', name: 'Makati Office'))
            ->addSite(new Site(id: 'S-2', name: 'Cebu Site'))
            ->addEmployee(new Employee(id: 'P-1', name: 'Erwin Engineer'))
            ->addEmployee(new Employee(id: 'P-2', name: 'Ella Engineer'));
    }

    private function actingAsSiteSettingsManager(bool $isAllowed = true): User
    {
        Gate::define('manage-site-settings', fn (User $user): bool => $isAllowed);

        $user = User::factory()->create();
        $this->actingAs($user);

        return $user;
    }

    public function test_setting_the_site_vendor_stores_it_with_a_site_snapshot(): void
    {
        $this->actingAsSiteSettingsManager();
        $vendor = Vendor::factory()->create(['name' => 'Acme Facilities']);

        $this->putJson('/api/sites/S-1/settings/vendor', ['vendor_id' => $vendor->id])
            ->assertOk()
            ->assertJsonPath('data.site_id', 'S-1')
            ->assertJsonPath('data.vendor.id', $vendor->id)
            ->assertJsonPath('data.vendor.name', 'Acme Facilities');

        $this->assertDatabaseHas('site_settings', [
            'site_id' => 'S-1',
            'site_name_snapshot' => 'Makati Office',
            'vendor_id' => $vendor->id,
        ]);
    }

    public function test_the_snapshot_survives_the_site_being_renamed_or_removed_upstream(): void
    {
        $this->actingAsSiteSettingsManager();
        $vendor = Vendor::factory()->create();
        $this->putJson('/api/sites/S-1/settings/vendor', ['vendor_id' => $vendor->id])->assertOk();

        $this->app->instance(MasterDataDirectory::class, new InMemoryMasterDataDirectory);

        $this->assertSame('Makati Office', SiteSetting::query()->where('site_id', 'S-1')->sole()->site_name_snapshot);
    }

    /**
     * @return array<string, array{string, string, array<string, mixed>}>
     */
    public static function endpointsForAnUnknownSite(): array
    {
        return [
            'show settings' => ['get', '/api/sites/S-unknown/settings', []],
            'set vendor' => ['put', '/api/sites/S-unknown/settings/vendor', ['vendor_id' => 1]],
            'set eic' => ['put', '/api/sites/S-unknown/settings/eic', ['employee_id' => 'P-1']],
            'set pm frequency' => ['put', '/api/sites/S-unknown/settings/pm-frequency', ['pm_frequency' => 'monthly']],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    #[DataProvider('endpointsForAnUnknownSite')]
    public function test_an_unknown_site_returns_404(string $method, string $uri, array $payload): void
    {
        $this->actingAsSiteSettingsManager();
        Vendor::factory()->create();

        $this->json($method, $uri, $payload)->assertNotFound();

        $this->assertDatabaseCount('site_settings', 0);
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function invalidVendorPayloads(): array
    {
        return [
            'missing vendor_id' => [[]],
            'vendor that does not exist' => [['vendor_id' => 9999]],
            'not an id' => [['vendor_id' => 'abc']],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    #[DataProvider('invalidVendorPayloads')]
    public function test_a_missing_vendor_returns_422(array $payload): void
    {
        $this->actingAsSiteSettingsManager();

        $this->putJson('/api/sites/S-1/settings/vendor', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['vendor_id']);

        $this->assertDatabaseCount('site_settings', 0);
        $this->assertDatabaseCount('site_assignment_changes', 0);
    }

    public function test_an_inactive_vendor_cannot_be_assigned(): void
    {
        $this->actingAsSiteSettingsManager();
        $inactiveVendor = Vendor::factory()->inactive()->create();

        $this->putJson('/api/sites/S-1/settings/vendor', ['vendor_id' => $inactiveVendor->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['vendor_id']);

        $this->assertDatabaseCount('site_settings', 0);
        $this->assertDatabaseCount('site_assignment_changes', 0);
    }

    public function test_replacing_the_site_vendor_keeps_one_current_vendor_and_records_history(): void
    {
        $user = $this->actingAsSiteSettingsManager();
        [$oldVendor, $newVendor] = Vendor::factory()->count(2)->create();

        $this->putJson('/api/sites/S-1/settings/vendor', ['vendor_id' => $oldVendor->id])->assertOk();
        $this->putJson('/api/sites/S-1/settings/vendor', ['vendor_id' => $newVendor->id])
            ->assertOk()
            ->assertJsonPath('data.vendor.id', $newVendor->id);

        $this->assertDatabaseCount('site_settings', 1);
        $this->assertSame($newVendor->id, SiteSetting::query()->where('site_id', 'S-1')->sole()->vendor_id);

        $changes = SiteAssignmentChange::query()->where('site_id', 'S-1')->orderBy('id')->get();
        $this->assertCount(2, $changes);
        $this->assertSame(SiteAssignment::Vendor, $changes[0]->assignment);
        $this->assertNull($changes[0]->old_vendor_id);
        $this->assertSame($oldVendor->id, $changes[0]->new_vendor_id);

        $replacement = $changes[1];
        $this->assertSame(SiteAssignment::Vendor, $replacement->assignment);
        $this->assertSame($oldVendor->id, $replacement->old_vendor_id);
        $this->assertSame($newVendor->id, $replacement->new_vendor_id);
        $this->assertSame($user->id, $replacement->changed_by_user_id);
        $this->assertNotNull($replacement->created_at);
    }

    public function test_assigning_the_same_vendor_again_records_no_history(): void
    {
        $this->actingAsSiteSettingsManager();
        $vendor = Vendor::factory()->create();

        $this->putJson('/api/sites/S-1/settings/vendor', ['vendor_id' => $vendor->id])->assertOk();
        $this->putJson('/api/sites/S-1/settings/vendor', ['vendor_id' => $vendor->id])->assertOk();

        $this->assertDatabaseCount('site_assignment_changes', 1);
    }

    public function test_each_site_has_its_own_vendor(): void
    {
        $this->actingAsSiteSettingsManager();
        [$makatiVendor, $cebuVendor] = Vendor::factory()->count(2)->create();

        $this->putJson('/api/sites/S-1/settings/vendor', ['vendor_id' => $makatiVendor->id])->assertOk();
        $this->putJson('/api/sites/S-2/settings/vendor', ['vendor_id' => $cebuVendor->id])->assertOk();

        $this->assertDatabaseHas('site_settings', ['site_id' => 'S-1', 'vendor_id' => $makatiVendor->id]);
        $this->assertDatabaseHas('site_settings', ['site_id' => 'S-2', 'vendor_id' => $cebuVendor->id, 'site_name_snapshot' => 'Cebu Site']);
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function invalidEicPayloads(): array
    {
        return [
            'employee unknown to the directory' => [['employee_id' => 'P-unknown']],
            'missing employee_id' => [[]],
            'employee_id that is not a string' => [['employee_id' => ['P-1']]],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    #[DataProvider('invalidEicPayloads')]
    public function test_an_unknown_eic_returns_422(array $payload): void
    {
        $this->actingAsSiteSettingsManager();

        $this->putJson('/api/sites/S-1/settings/eic', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['employee_id']);

        $this->assertDatabaseCount('site_settings', 0);
        $this->assertDatabaseCount('site_assignment_changes', 0);
    }

    public function test_setting_and_replacing_the_eic_stores_it_and_records_history(): void
    {
        $user = $this->actingAsSiteSettingsManager();

        $this->putJson('/api/sites/S-1/settings/eic', ['employee_id' => 'P-1'])
            ->assertOk()
            ->assertJsonPath('data.eic.employee_id', 'P-1')
            ->assertJsonPath('data.eic.name', 'Erwin Engineer');
        $this->putJson('/api/sites/S-1/settings/eic', ['employee_id' => 'P-2'])->assertOk();

        $this->assertDatabaseCount('site_settings', 1);
        $this->assertDatabaseHas('site_settings', [
            'site_id' => 'S-1',
            'site_name_snapshot' => 'Makati Office',
            'eic_employee_id' => 'P-2',
        ]);

        $changes = SiteAssignmentChange::query()->where('site_id', 'S-1')->orderBy('id')->get();
        $this->assertCount(2, $changes);
        $this->assertSame(SiteAssignment::Eic, $changes[1]->assignment);
        $this->assertSame('P-1', $changes[1]->old_eic_employee_id);
        $this->assertSame('P-2', $changes[1]->new_eic_employee_id);
        $this->assertSame($user->id, $changes[1]->changed_by_user_id);
        $this->assertNotNull($changes[1]->created_at);
    }

    public function test_setting_the_eic_keeps_the_current_vendor(): void
    {
        $this->actingAsSiteSettingsManager();
        $vendor = Vendor::factory()->create();

        $this->putJson('/api/sites/S-1/settings/vendor', ['vendor_id' => $vendor->id])->assertOk();
        $this->putJson('/api/sites/S-1/settings/eic', ['employee_id' => 'P-1'])
            ->assertOk()
            ->assertJsonPath('data.vendor.id', $vendor->id);

        $this->assertDatabaseHas('site_settings', ['site_id' => 'S-1', 'vendor_id' => $vendor->id, 'eic_employee_id' => 'P-1']);
    }

    /**
     * @return array<string, array{string, array<string, mixed>}>
     */
    public static function protectedUpdates(): array
    {
        return [
            'set vendor' => ['/api/sites/S-1/settings/vendor', ['vendor_id' => null]],
            'set eic' => ['/api/sites/S-1/settings/eic', ['employee_id' => 'P-1']],
            'set pm frequency' => ['/api/sites/S-1/settings/pm-frequency', ['pm_frequency' => 'monthly']],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    #[DataProvider('protectedUpdates')]
    public function test_updates_return_403_when_the_gate_denies(string $uri, array $payload): void
    {
        $this->actingAsSiteSettingsManager(isAllowed: false);
        $payload['vendor_id'] ??= Vendor::factory()->create()->id;

        $this->putJson($uri, $payload)->assertForbidden();

        $this->assertDatabaseCount('site_settings', 0);
        $this->assertDatabaseCount('site_assignment_changes', 0);
    }

    public function test_show_returns_nulls_when_nothing_is_assigned(): void
    {
        $this->actingAsSiteSettingsManager(isAllowed: false);

        $this->getJson('/api/sites/S-1/settings')
            ->assertOk()
            ->assertExactJson(['data' => [
                'site_id' => 'S-1',
                'site_name' => 'Makati Office',
                'vendor' => null,
                'eic' => null,
                'pm_frequency' => null,
            ]]);
    }

    public function test_show_returns_the_current_vendor_and_eic(): void
    {
        $this->actingAsSiteSettingsManager();
        [$oldVendor, $currentVendor] = Vendor::factory()->count(2)->create();
        $this->putJson('/api/sites/S-1/settings/vendor', ['vendor_id' => $oldVendor->id])->assertOk();
        $this->putJson('/api/sites/S-1/settings/vendor', ['vendor_id' => $currentVendor->id])->assertOk();
        $this->putJson('/api/sites/S-1/settings/eic', ['employee_id' => 'P-2'])->assertOk();

        $this->getJson('/api/sites/S-1/settings')
            ->assertOk()
            ->assertExactJson(['data' => [
                'site_id' => 'S-1',
                'site_name' => 'Makati Office',
                'vendor' => ['id' => $currentVendor->id, 'name' => $currentVendor->name],
                'eic' => ['employee_id' => 'P-2', 'name' => 'Ella Engineer'],
                'pm_frequency' => null,
            ]]);
    }

    public function test_setting_the_pm_frequency_stores_it_and_show_returns_it(): void
    {
        $this->actingAsSiteSettingsManager();

        $this->putJson('/api/sites/S-1/settings/pm-frequency', ['pm_frequency' => 'quarterly'])
            ->assertOk()
            ->assertJsonPath('data.site_id', 'S-1')
            ->assertJsonPath('data.pm_frequency', 'quarterly');

        $this->assertDatabaseHas('site_settings', ['site_id' => 'S-1', 'site_name_snapshot' => 'Makati Office', 'pm_frequency' => 'quarterly']);

        $this->getJson('/api/sites/S-1/settings')
            ->assertOk()
            ->assertJsonPath('data.pm_frequency', 'quarterly')
            ->assertJsonPath('data.vendor', null);
    }

    public function test_every_mvp_pm_frequency_is_accepted(): void
    {
        $this->actingAsSiteSettingsManager();

        foreach (PmFrequency::cases() as $frequency) {
            $this->putJson('/api/sites/S-1/settings/pm-frequency', ['pm_frequency' => $frequency->value])
                ->assertOk()
                ->assertJsonPath('data.pm_frequency', $frequency->value);
        }
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function invalidPmFrequencyPayloads(): array
    {
        return [
            'unknown value' => [['pm_frequency' => 'weekly']],
            'missing pm_frequency' => [[]],
            'null' => [['pm_frequency' => null]],
            'custom interval' => [['pm_frequency' => 30]],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    #[DataProvider('invalidPmFrequencyPayloads')]
    public function test_an_invalid_pm_frequency_returns_422(array $payload): void
    {
        Event::fake([SitePmFrequencyChanged::class]);
        $this->actingAsSiteSettingsManager();

        $this->putJson('/api/sites/S-1/settings/pm-frequency', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['pm_frequency']);

        $this->assertDatabaseCount('site_settings', 0);
        $this->assertDatabaseCount('site_assignment_changes', 0);
        Event::assertNotDispatched(SitePmFrequencyChanged::class);
    }

    public function test_changing_the_pm_frequency_records_history_and_dispatches_an_event(): void
    {
        Event::fake([SitePmFrequencyChanged::class]);
        $user = $this->actingAsSiteSettingsManager();

        $this->putJson('/api/sites/S-1/settings/pm-frequency', ['pm_frequency' => 'monthly'])->assertOk();
        $this->putJson('/api/sites/S-1/settings/pm-frequency', ['pm_frequency' => 'annual'])->assertOk();

        $changes = SiteAssignmentChange::query()->where('site_id', 'S-1')->orderBy('id')->get();
        $this->assertCount(2, $changes);

        $this->assertSame(SiteAssignment::PmFrequency, $changes[0]->assignment);
        $this->assertNull($changes[0]->old_pm_frequency);
        $this->assertSame(PmFrequency::Monthly, $changes[0]->new_pm_frequency);

        $change = $changes[1];
        $this->assertSame(SiteAssignment::PmFrequency, $change->assignment);
        $this->assertSame(PmFrequency::Monthly, $change->old_pm_frequency);
        $this->assertSame(PmFrequency::Annual, $change->new_pm_frequency);
        $this->assertSame($user->id, $change->changed_by_user_id);
        $this->assertNotNull($change->created_at);
        $this->assertNull($change->new_vendor_id);
        $this->assertNull($change->new_eic_employee_id);

        Event::assertDispatchedTimes(SitePmFrequencyChanged::class, 2);
        Event::assertDispatched(
            SitePmFrequencyChanged::class,
            fn (SitePmFrequencyChanged $event): bool => $event->siteId === 'S-1'
                && $event->oldFrequency === null
                && $event->newFrequency === PmFrequency::Monthly,
        );
        Event::assertDispatched(
            SitePmFrequencyChanged::class,
            fn (SitePmFrequencyChanged $event): bool => $event->siteId === 'S-1'
                && $event->oldFrequency === PmFrequency::Monthly
                && $event->newFrequency === PmFrequency::Annual,
        );
    }

    public function test_setting_the_same_pm_frequency_again_records_nothing_and_dispatches_nothing(): void
    {
        $this->actingAsSiteSettingsManager();
        $this->putJson('/api/sites/S-1/settings/pm-frequency', ['pm_frequency' => 'monthly'])->assertOk();

        Event::fake([SitePmFrequencyChanged::class]);

        $this->putJson('/api/sites/S-1/settings/pm-frequency', ['pm_frequency' => 'monthly'])
            ->assertOk()
            ->assertJsonPath('data.pm_frequency', 'monthly');

        $this->assertDatabaseCount('site_assignment_changes', 1);
        Event::assertNotDispatched(SitePmFrequencyChanged::class);
    }

    public function test_setting_the_pm_frequency_keeps_the_vendor_and_eic(): void
    {
        $this->actingAsSiteSettingsManager();
        $vendor = Vendor::factory()->create();
        $this->putJson('/api/sites/S-1/settings/vendor', ['vendor_id' => $vendor->id])->assertOk();
        $this->putJson('/api/sites/S-1/settings/eic', ['employee_id' => 'P-1'])->assertOk();

        $this->putJson('/api/sites/S-1/settings/pm-frequency', ['pm_frequency' => 'semi_annual'])
            ->assertOk()
            ->assertJsonPath('data.vendor.id', $vendor->id)
            ->assertJsonPath('data.eic.employee_id', 'P-1');

        $this->assertDatabaseHas('site_settings', [
            'site_id' => 'S-1',
            'vendor_id' => $vendor->id,
            'eic_employee_id' => 'P-1',
            'pm_frequency' => 'semi_annual',
        ]);
    }

    public function test_changing_the_vendor_does_not_dispatch_a_pm_frequency_event(): void
    {
        Event::fake([SitePmFrequencyChanged::class]);
        $this->actingAsSiteSettingsManager();

        $this->putJson('/api/sites/S-1/settings/vendor', ['vendor_id' => Vendor::factory()->create()->id])->assertOk();

        Event::assertNotDispatched(SitePmFrequencyChanged::class);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function allEndpoints(): array
    {
        return [
            'show settings' => ['get', '/api/sites/S-1/settings'],
            'set vendor' => ['put', '/api/sites/S-1/settings/vendor'],
            'set eic' => ['put', '/api/sites/S-1/settings/eic'],
            'set pm frequency' => ['put', '/api/sites/S-1/settings/pm-frequency'],
        ];
    }

    #[DataProvider('allEndpoints')]
    public function test_every_endpoint_returns_401_when_unauthenticated(string $method, string $uri): void
    {
        $this->json($method, $uri, ['vendor_id' => 1, 'employee_id' => 'P-1', 'pm_frequency' => 'monthly'])->assertUnauthorized();

        $this->assertDatabaseCount('site_settings', 0);
    }
}
