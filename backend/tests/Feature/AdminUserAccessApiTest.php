<?php

namespace Tests\Feature;

use App\Enums\PssRole;
use App\Enums\UserAccess;
use App\MasterData\Data\Employee;
use App\MasterData\InMemoryMasterDataDirectory;
use App\MasterData\MasterDataDirectory;
use App\Models\SiteSetting;
use App\Models\User;
use App\Models\Vendor;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Admin-created users and access states (issue 46, ADR 0005): adding a user, classifying a pending user, the access
 * filter, and no roles for pending users.
 */
class AdminUserAccessApiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var InMemoryMasterDataDirectory $directory */
        $directory = $this->app->make(MasterDataDirectory::class);
        $directory->addEmployee(new Employee(id: 'EMP-NEW', name: 'Nina Newcomer'));
        $directory->addEmployee(new Employee(id: 'EMP-EIC', name: 'Eric Engineer'));

        $this->admin = User::factory()->create(['employee_id' => 'EMP-ADMIN', 'name' => 'Ada Admin', 'email' => 'ada@example.test']);
        $this->admin->grantRole(PssRole::SystemAdministrator);
        $this->travelTo('2026-11-24 09:30:00');
    }

    public function test_adding_an_internal_user_copies_the_master_data_name_and_grants_no_roles(): void
    {
        $this->actingAs($this->admin);

        $this->postJson('/api/admin/users', ['employee_id' => 'EMP-NEW', 'access' => 'internal', 'email' => 'nina@example.test', 'temporary_password' => 'Temporary-Pass-1'])
            ->assertCreated()
            ->assertJsonPath('data.employee_id', 'EMP-NEW')
            ->assertJsonPath('data.name', 'Nina Newcomer')
            ->assertJsonPath('data.roles', [])
            ->assertJsonPath('data.vendor_id', null)
            ->assertJsonPath('data.access', 'internal')
            ->assertJsonPath('data.email', 'nina@example.test')
            ->assertJsonPath('data.must_change_password', true)
            ->assertJsonMissingPath('data.password')
            ->assertJsonMissingPath('data.temporary_password')
            ->assertJsonStructure(['data' => ['id', 'employee_id', 'email', 'name', 'roles', 'vendor_id', 'access', 'must_change_password']]);

        $user = User::query()->where('employee_id', 'EMP-NEW')->sole();
        $this->assertTrue($user->must_change_password);
        $this->assertNotSame('Temporary-Pass-1', $user->getAuthPassword());
        $this->assertTrue(Hash::check('Temporary-Pass-1', $user->getAuthPassword()));
        $this->assertSame(UserAccess::Internal, $user->access);
        $this->assertSame($this->admin->id, $user->access_set_by_user_id);
        $this->assertSame([], $user->roleKeys());
    }

    public function test_adding_a_vendor_staff_user_links_the_vendor(): void
    {
        $vendor = Vendor::factory()->create();
        $this->actingAs($this->admin);

        $this->postJson('/api/admin/users', ['employee_id' => 'EMP-NEW', 'vendor_id' => $vendor->id, 'email' => 'nina@example.test', 'temporary_password' => 'Temporary-Pass-1'])
            ->assertCreated()
            ->assertJsonPath('data.vendor_id', $vendor->id)
            ->assertJsonPath('data.access', 'vendor');

        $user = User::query()->where('employee_id', 'EMP-NEW')->sole();
        $this->assertSame(UserAccess::Vendor, $user->access);
        $this->assertSame($vendor->id, $user->vendor_id);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidNewUsers(): array
    {
        $valid = ['email' => 'nina@example.test', 'temporary_password' => 'Temporary-Pass-1'];

        return [
            'missing employee id' => [['access' => 'internal', ...$valid], 'employee_id'],
            'employee unknown to master data' => [['employee_id' => 'EMP-GHOST', 'access' => 'internal', ...$valid], 'employee_id'],
            'employee already a pss user' => [['employee_id' => 'EMP-ADMIN', 'access' => 'internal', ...$valid], 'employee_id'],
            'neither access nor vendor' => [['employee_id' => 'EMP-NEW', ...$valid], 'access'],
            'both access and vendor' => [['employee_id' => 'EMP-NEW', 'access' => 'internal', 'vendor_id' => 'VENDOR', ...$valid], 'access'],
            'access vendor' => [['employee_id' => 'EMP-NEW', 'access' => 'vendor', ...$valid], 'access'],
            'access pending' => [['employee_id' => 'EMP-NEW', 'access' => 'pending', ...$valid], 'access'],
            'unknown vendor' => [['employee_id' => 'EMP-NEW', 'vendor_id' => 999_999, ...$valid], 'vendor_id'],
            'missing email' => [['employee_id' => 'EMP-NEW', 'access' => 'internal', 'temporary_password' => 'Temporary-Pass-1'], 'email'],
            'invalid email' => [['employee_id' => 'EMP-NEW', 'access' => 'internal', ...$valid, 'email' => 'not-an-email'], 'email'],
            'email already used' => [['employee_id' => 'EMP-NEW', 'access' => 'internal', ...$valid, 'email' => 'ada@example.test'], 'email'],
            'email already used in another case' => [['employee_id' => 'EMP-NEW', 'access' => 'internal', ...$valid, 'email' => 'ADA@Example.test'], 'email'],
            'missing temporary password' => [['employee_id' => 'EMP-NEW', 'access' => 'internal', 'email' => 'nina@example.test'], 'temporary_password'],
            'temporary password too short' => [['employee_id' => 'EMP-NEW', 'access' => 'internal', ...$valid, 'temporary_password' => 'short'], 'temporary_password'],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    #[DataProvider('invalidNewUsers')]
    public function test_an_invalid_new_user_gives_422_and_stores_nothing(array $payload, string $errorKey): void
    {
        $vendor = Vendor::factory()->create();
        if (($payload['vendor_id'] ?? null) === 'VENDOR') {
            $payload['vendor_id'] = $vendor->id;
        }
        $this->actingAs($this->admin);

        $this->postJson('/api/admin/users', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$errorKey]);

        $this->assertSame(1, User::query()->count());
    }

    public function test_a_current_site_eic_cannot_be_added_as_vendor_staff_and_nothing_is_stored(): void
    {
        SiteSetting::factory()->create(['eic_employee_id' => 'EMP-EIC']);
        $vendor = Vendor::factory()->create();
        $this->actingAs($this->admin);

        $this->postJson('/api/admin/users', ['employee_id' => 'EMP-EIC', 'vendor_id' => $vendor->id, 'email' => 'eric@example.test', 'temporary_password' => 'Temporary-Pass-1'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['vendor_id' => 'replace them as EIC first']);

        $this->assertNull(User::query()->firstWhere('employee_id', 'EMP-EIC'));
    }

    public function test_setting_a_pending_user_internal_records_who_and_when(): void
    {
        $user = User::factory()->pending()->create(['employee_id' => 'EMP-P']);
        $this->actingAs($this->admin);

        $this->putJson("/api/admin/users/{$user->id}/access", ['access' => 'internal'])
            ->assertOk()
            ->assertJsonPath('data.access', 'internal');

        $user->refresh();
        $this->assertSame(UserAccess::Internal, $user->access);
        $this->assertSame($this->admin->id, $user->access_set_by_user_id);
        $this->assertSame('2026-11-24 09:30:00', $user->access_set_at->format('Y-m-d H:i:s'));
    }

    public function test_linking_a_pending_user_to_a_vendor_records_who_and_when(): void
    {
        $user = User::factory()->pending()->create(['employee_id' => 'EMP-P']);
        $vendor = Vendor::factory()->create();
        $this->actingAs($this->admin);

        $this->putJson("/api/admin/users/{$user->id}/vendor", ['vendor_id' => $vendor->id])
            ->assertOk()
            ->assertJsonPath('data.access', 'vendor');

        $user->refresh();
        $this->assertSame(UserAccess::Vendor, $user->access);
        $this->assertSame($this->admin->id, $user->access_set_by_user_id);
        $this->assertSame('2026-11-24 09:30:00', $user->access_set_at->format('Y-m-d H:i:s'));
    }

    public function test_unlinking_a_vendor_makes_the_user_pending(): void
    {
        $user = User::factory()->vendorStaff()->create(['employee_id' => 'EMP-V']);
        $this->actingAs($this->admin);

        $this->putJson("/api/admin/users/{$user->id}/vendor", ['vendor_id' => null])
            ->assertOk()
            ->assertJsonPath('data.vendor_id', null)
            ->assertJsonPath('data.access', 'pending');

        $this->assertSame(UserAccess::Pending, $user->refresh()->access);
    }

    /**
     * @return array<string, array{Closure(): User, UserAccess}>
     */
    public static function usersWithoutAVendor(): array
    {
        return [
            'internal' => [fn (): User => User::factory()->create(['employee_id' => 'EMP-I']), UserAccess::Internal],
            'pending' => [fn (): User => User::factory()->pending()->create(['employee_id' => 'EMP-P']), UserAccess::Pending],
        ];
    }

    /**
     * @param  Closure(): User  $makeUser
     */
    #[DataProvider('usersWithoutAVendor')]
    public function test_unlinking_a_user_without_a_vendor_is_a_no_op(Closure $makeUser, UserAccess $access): void
    {
        $user = $makeUser();
        $this->actingAs($this->admin);

        $this->putJson("/api/admin/users/{$user->id}/vendor", ['vendor_id' => null])
            ->assertOk()
            ->assertJsonPath('data.access', $access->value);

        $this->assertSame($access, $user->refresh()->access);
        $this->assertNull($user->access_set_at);
    }

    public function test_setting_a_vendor_linked_user_internal_gives_422_and_changes_nothing(): void
    {
        $user = User::factory()->vendorStaff()->create(['employee_id' => 'EMP-V']);
        $this->actingAs($this->admin);

        $this->putJson("/api/admin/users/{$user->id}/access", ['access' => 'internal'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['access' => 'unlink the Vendor first']);

        $this->assertSame(UserAccess::Vendor, $user->refresh()->access);
        $this->assertNotNull($user->vendor_id);
    }

    public function test_setting_an_internal_user_internal_is_a_no_op_200(): void
    {
        $user = User::factory()->create(['employee_id' => 'EMP-I']);
        $this->actingAs($this->admin);

        $this->putJson("/api/admin/users/{$user->id}/access", ['access' => 'internal'])
            ->assertOk()
            ->assertJsonPath('data.access', 'internal');

        $this->assertNull($user->refresh()->access_set_at);
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function invalidAccessPayloads(): array
    {
        return [
            'missing' => [[]],
            'vendor' => [['access' => 'vendor']],
            'pending' => [['access' => 'pending']],
            'unknown' => [['access' => 'superuser']],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    #[DataProvider('invalidAccessPayloads')]
    public function test_an_invalid_access_value_gives_422(array $payload): void
    {
        $user = User::factory()->pending()->create(['employee_id' => 'EMP-P']);
        $this->actingAs($this->admin);

        $this->putJson("/api/admin/users/{$user->id}/access", $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['access']);

        $this->assertSame(UserAccess::Pending, $user->refresh()->access);
    }

    public function test_granting_a_role_to_a_pending_user_gives_422_and_stores_nothing(): void
    {
        $user = User::factory()->pending()->create(['employee_id' => 'EMP-P']);
        $this->actingAs($this->admin);

        $this->postJson("/api/admin/users/{$user->id}/roles", ['role' => 'management-viewer'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['role' => 'Classify this user first (internal or a Vendor) before granting roles.']);

        $this->assertSame([], $user->refresh()->roleKeys());
    }

    public function test_the_list_returns_access_and_filters_by_it(): void
    {
        User::factory()->vendorStaff()->create(['employee_id' => 'EMP-V', 'name' => 'Victor Vendor']);
        User::factory()->pending()->create(['employee_id' => 'EMP-P', 'name' => 'Paula Pending']);
        $this->actingAs($this->admin);

        $this->getJson('/api/admin/users')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.access', 'internal')
            ->assertJsonPath('data.1.access', 'pending')
            ->assertJsonPath('data.2.access', 'vendor');

        $this->getJson('/api/admin/users?access=pending')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.employee_id', 'EMP-P');
        $this->getJson('/api/admin/users?access=internal')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.employee_id', 'EMP-ADMIN');
        $this->getJson('/api/admin/users?access=vendor')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.employee_id', 'EMP-V');
    }

    public function test_an_unknown_access_filter_gives_422(): void
    {
        $this->actingAs($this->admin);

        $this->getJson('/api/admin/users?access=everyone')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['access']);
    }

    public function test_only_a_system_administrator_may_add_users_or_set_access(): void
    {
        $target = User::factory()->pending()->create(['employee_id' => 'EMP-P']);
        $viewer = User::factory()->create(['employee_id' => 'EMP-VIEW']);
        $viewer->grantRole(PssRole::ManagementViewer);

        foreach ([User::factory()->create(['employee_id' => 'EMP-1']), $viewer, User::factory()->vendorStaff()->create()] as $user) {
            $this->actingAs($user);

            $this->postJson('/api/admin/users', ['employee_id' => 'EMP-NEW', 'access' => 'internal', 'email' => 'nina@example.test', 'temporary_password' => 'Temporary-Pass-1'])->assertForbidden();
            $this->putJson("/api/admin/users/{$target->id}/access", ['access' => 'internal'])->assertForbidden();
        }

        $this->assertNull(User::query()->firstWhere('employee_id', 'EMP-NEW'));
        $this->assertSame(UserAccess::Pending, $target->refresh()->access);
    }

    public function test_the_new_endpoints_give_401_without_a_token(): void
    {
        $this->postJson('/api/admin/users', ['employee_id' => 'EMP-NEW', 'access' => 'internal', 'email' => 'nina@example.test', 'temporary_password' => 'Temporary-Pass-1'])->assertUnauthorized();
        $this->putJson("/api/admin/users/{$this->admin->id}/access", ['access' => 'internal'])->assertUnauthorized();
    }

    public function test_the_model_keeps_vendor_exactly_when_vendor_id_is_set(): void
    {
        $vendor = Vendor::factory()->create();
        $user = User::factory()->create(['employee_id' => 'EMP-I']);

        $user->forceFill(['vendor_id' => $vendor->id])->save();
        $this->assertSame(UserAccess::Vendor, $user->refresh()->access);

        $user->forceFill(['vendor_id' => null])->save();
        $this->assertSame(UserAccess::Pending, $user->refresh()->access);

        $this->assertSame(UserAccess::Pending, User::query()->create(['name' => 'New', 'employee_id' => 'EMP-X'])->refresh()->access);
    }
}
