<?php

namespace Tests\Feature;

use App\Enums\PssRole;
use App\Models\SiteSetting;
use App\Models\User;
use App\Models\Vendor;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The admin API behind `manage-roles` (issue 04): list users, grant and revoke PSS Roles, link users to a Vendor.
 */
class AdminUserApiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['employee_id' => 'EMP-ADMIN', 'name' => 'Ada Admin']);
        $this->admin->grantRole(PssRole::SystemAdministrator);
    }

    private function actingAsAdmin(): void
    {
        $this->actingAs($this->admin);
    }

    public function test_it_lists_users_with_identity_roles_and_vendor(): void
    {
        $vendor = Vendor::factory()->create();
        User::factory()->vendorStaff($vendor)->create(['employee_id' => 'EMP-V', 'name' => 'Victor Vendor']);
        $this->actingAsAdmin();

        $this->getJson('/api/admin/users')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.employee_id', 'EMP-ADMIN')
            ->assertJsonPath('data.0.name', 'Ada Admin')
            ->assertJsonPath('data.0.roles', ['system-administrator'])
            ->assertJsonPath('data.0.vendor_id', null)
            ->assertJsonPath('data.1.employee_id', 'EMP-V')
            ->assertJsonPath('data.1.roles', [])
            ->assertJsonPath('data.1.vendor_id', $vendor->id);
    }

    public function test_granting_a_role_stores_it(): void
    {
        $user = User::factory()->create(['employee_id' => 'EMP-1']);
        $this->actingAsAdmin();

        $this->postJson("/api/admin/users/{$user->id}/roles", ['role' => 'management-viewer'])
            ->assertOk()
            ->assertJsonPath('data.roles', ['management-viewer']);

        $this->assertDatabaseHas('user_roles', ['user_id' => $user->id, 'role' => 'management-viewer']);
    }

    public function test_granting_a_role_the_user_already_has_is_a_no_op_200(): void
    {
        $user = User::factory()->create(['employee_id' => 'EMP-1']);
        $user->grantRole(PssRole::ManagementViewer);
        $this->actingAsAdmin();

        $this->postJson("/api/admin/users/{$user->id}/roles", ['role' => 'management-viewer'])
            ->assertOk()
            ->assertJsonPath('data.roles', ['management-viewer']);

        $this->assertDatabaseCount('user_roles', 2);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function invalidRoles(): array
    {
        return [
            'an unknown key' => ['engineer-in-charge'],
            'missing' => [null],
            'not a string' => [['system-administrator']],
        ];
    }

    #[DataProvider('invalidRoles')]
    public function test_granting_any_other_role_key_gives_422(mixed $role): void
    {
        $user = User::factory()->create(['employee_id' => 'EMP-1']);
        $this->actingAsAdmin();

        $this->postJson("/api/admin/users/{$user->id}/roles", ['role' => $role])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['role']);

        $this->assertDatabaseMissing('user_roles', ['user_id' => $user->id]);
    }

    public function test_revoking_a_role_removes_it(): void
    {
        $user = User::factory()->create(['employee_id' => 'EMP-1']);
        $user->grantRole(PssRole::ManagementViewer);
        $this->actingAsAdmin();

        $this->deleteJson("/api/admin/users/{$user->id}/roles/management-viewer")
            ->assertOk()
            ->assertJsonPath('data.roles', []);

        $this->assertDatabaseMissing('user_roles', ['user_id' => $user->id]);
    }

    public function test_revoking_a_role_the_user_does_not_have_is_a_no_op_200(): void
    {
        $user = User::factory()->create(['employee_id' => 'EMP-1']);
        $this->actingAsAdmin();

        $this->deleteJson("/api/admin/users/{$user->id}/roles/management-viewer")->assertOk();
    }

    public function test_revoking_an_unknown_role_key_gives_422(): void
    {
        $user = User::factory()->create(['employee_id' => 'EMP-1']);
        $this->actingAsAdmin();

        $this->deleteJson("/api/admin/users/{$user->id}/roles/engineer-in-charge")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['role']);
    }

    public function test_the_last_system_administrator_cannot_remove_the_role_from_themselves(): void
    {
        $this->actingAsAdmin();

        $this->deleteJson("/api/admin/users/{$this->admin->id}/roles/system-administrator")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['role' => 'last System Administrator']);

        $this->assertTrue($this->admin->refresh()->hasRole(PssRole::SystemAdministrator));
    }

    public function test_with_two_admins_either_can_remove_the_others_role(): void
    {
        $other = User::factory()->create(['employee_id' => 'EMP-ADMIN-2']);
        $other->grantRole(PssRole::SystemAdministrator);
        $this->actingAsAdmin();

        $this->deleteJson("/api/admin/users/{$other->id}/roles/system-administrator")->assertOk();

        $this->assertFalse($other->refresh()->hasRole(PssRole::SystemAdministrator));

        $this->deleteJson("/api/admin/users/{$this->admin->id}/roles/system-administrator")->assertUnprocessable();
    }

    public function test_with_two_admins_one_can_remove_their_own_role(): void
    {
        $other = User::factory()->create(['employee_id' => 'EMP-ADMIN-2']);
        $other->grantRole(PssRole::SystemAdministrator);
        $this->actingAsAdmin();

        $this->deleteJson("/api/admin/users/{$this->admin->id}/roles/system-administrator")->assertOk();

        $this->assertFalse($this->admin->refresh()->hasRole(PssRole::SystemAdministrator));
        $this->assertTrue($other->refresh()->hasRole(PssRole::SystemAdministrator));
    }

    public function test_linking_a_user_to_a_vendor_and_unlinking_them(): void
    {
        $user = User::factory()->create(['employee_id' => 'EMP-V']);
        $vendor = Vendor::factory()->create();
        $this->actingAsAdmin();

        $this->putJson("/api/admin/users/{$user->id}/vendor", ['vendor_id' => $vendor->id])
            ->assertOk()
            ->assertJsonPath('data.vendor_id', $vendor->id);
        $this->assertSame($vendor->id, $user->refresh()->vendor_id);

        $this->putJson("/api/admin/users/{$user->id}/vendor", ['vendor_id' => null])
            ->assertOk()
            ->assertJsonPath('data.vendor_id', null);
        $this->assertNull($user->refresh()->vendor_id);
        $this->assertTrue($user->isInternalEmployee());
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function invalidVendorPayloads(): array
    {
        return [
            'unknown vendor' => [['vendor_id' => 999_999]],
            'missing key' => [[]],
            'not an integer' => [['vendor_id' => 'acme']],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    #[DataProvider('invalidVendorPayloads')]
    public function test_linking_with_an_invalid_vendor_gives_422(array $payload): void
    {
        $user = User::factory()->create(['employee_id' => 'EMP-V']);
        $this->actingAsAdmin();

        $this->putJson("/api/admin/users/{$user->id}/vendor", $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['vendor_id']);

        $this->assertNull($user->refresh()->vendor_id);
    }

    public function test_linking_a_current_site_eic_is_refused_and_changes_nothing(): void
    {
        $user = User::factory()->create(['employee_id' => 'EMP-EIC']);
        SiteSetting::factory()->create(['eic_employee_id' => 'EMP-EIC']);
        $vendor = Vendor::factory()->create();
        $this->actingAsAdmin();

        $this->putJson("/api/admin/users/{$user->id}/vendor", ['vendor_id' => $vendor->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['vendor_id' => 'replace them as EIC first']);

        $this->assertNull($user->refresh()->vendor_id);
    }

    public function test_unlinking_is_allowed_for_anyone(): void
    {
        $vendor = Vendor::factory()->create();
        $user = User::factory()->vendorStaff($vendor)->create(['employee_id' => 'EMP-EIC']);
        SiteSetting::factory()->create(['eic_employee_id' => 'EMP-EIC']);
        $this->actingAsAdmin();

        $this->putJson("/api/admin/users/{$user->id}/vendor", ['vendor_id' => null])->assertOk();
    }

    public function test_an_unknown_user_gives_404(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/admin/users/999999/roles', ['role' => 'management-viewer'])->assertNotFound();
    }

    /**
     * @return array<string, array{Closure(): User}>
     */
    public static function nonAdmins(): array
    {
        return [
            'an ordinary employee' => [fn (): User => User::factory()->create(['employee_id' => 'EMP-1'])],
            'a management viewer' => [function (): User {
                $user = User::factory()->create(['employee_id' => 'EMP-VIEW']);
                $user->grantRole(PssRole::ManagementViewer);

                return $user;
            }],
            'vendor staff' => [fn (): User => User::factory()->vendorStaff()->create(['employee_id' => 'EMP-V'])],
        ];
    }

    /**
     * @param  Closure(): User  $makeUser
     */
    #[DataProvider('nonAdmins')]
    public function test_everyone_else_gets_403_on_every_endpoint(Closure $makeUser): void
    {
        $target = User::factory()->create(['employee_id' => 'EMP-T']);
        $vendor = Vendor::factory()->create();
        $this->actingAs($makeUser());

        $this->getJson('/api/admin/users')->assertForbidden();
        $this->postJson("/api/admin/users/{$target->id}/roles", ['role' => 'system-administrator'])->assertForbidden();
        $this->deleteJson("/api/admin/users/{$this->admin->id}/roles/system-administrator")->assertForbidden();
        $this->putJson("/api/admin/users/{$target->id}/vendor", ['vendor_id' => $vendor->id])->assertForbidden();

        $this->assertNull($target->refresh()->vendor_id);
        $this->assertSame([], $target->roleKeys());
    }

    public function test_every_endpoint_gives_401_without_a_token(): void
    {
        $this->getJson('/api/admin/users')->assertUnauthorized();
        $this->postJson("/api/admin/users/{$this->admin->id}/roles", ['role' => 'management-viewer'])->assertUnauthorized();
        $this->deleteJson("/api/admin/users/{$this->admin->id}/roles/system-administrator")->assertUnauthorized();
        $this->putJson("/api/admin/users/{$this->admin->id}/vendor", ['vendor_id' => null])->assertUnauthorized();
    }
}
