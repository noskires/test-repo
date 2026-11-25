<?php

namespace Tests\Feature;

use App\Enums\PssRole;
use App\MasterData\Data\Employee;
use App\MasterData\MasterDataDirectory;
use App\Models\User;
use App\Models\UserRole;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Vendor-linked users can't be System Administrators (issue 45, Q2/Q11/Q3/Q12).
 */
class VendorAdminRefusalTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['employee_id' => 'EMP-ADMIN']);
        $this->admin->grantRole(PssRole::SystemAdministrator);
    }

    public function test_granting_system_administrator_to_vendor_staff_gives_422_and_stores_nothing(): void
    {
        $staff = User::factory()->vendorStaff(Vendor::factory()->create())->create();

        $this->actingAs($this->admin)
            ->postJson("/api/admin/users/{$staff->id}/roles", ['role' => 'system-administrator'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.role.0', 'A user linked to a Vendor cannot be a System Administrator. Unlink the Vendor first.');

        $this->assertSame([], $staff->refresh()->roleKeys());
    }

    public function test_granting_management_viewer_to_vendor_staff_still_works(): void
    {
        $staff = User::factory()->vendorStaff(Vendor::factory()->create())->create();

        $this->actingAs($this->admin)
            ->postJson("/api/admin/users/{$staff->id}/roles", ['role' => 'management-viewer'])
            ->assertSuccessful();

        $this->assertSame(['management-viewer'], $staff->refresh()->roleKeys());
    }

    public function test_an_internal_user_can_still_be_granted_system_administrator(): void
    {
        $user = User::factory()->create();

        $this->actingAs($this->admin)
            ->postJson("/api/admin/users/{$user->id}/roles", ['role' => 'system-administrator'])
            ->assertSuccessful();
    }

    public function test_a_user_created_with_a_vendor_still_can_not_be_granted_system_administrator(): void
    {
        $vendor = Vendor::factory()->create();
        $this->app->make(MasterDataDirectory::class)
            ->addEmployee(new Employee(id: 'EMP-NEW', name: 'Nina'));

        $this->actingAs($this->admin)
            ->postJson('/api/admin/users', ['employee_id' => 'EMP-NEW', 'vendor_id' => $vendor->id, 'email' => 'nina@example.test', 'temporary_password' => 'Temporary-Pass-1'])
            ->assertCreated();
        $id = User::query()->where('employee_id', 'EMP-NEW')->value('id');

        $this->postJson("/api/admin/users/{$id}/roles", ['role' => 'system-administrator'])->assertUnprocessable();
    }

    public function test_linking_a_system_administrator_to_a_vendor_gives_422_and_changes_nothing(): void
    {
        $other = User::factory()->create();
        $other->grantRole(PssRole::SystemAdministrator);
        $vendor = Vendor::factory()->create();

        $this->actingAs($this->admin)
            ->putJson("/api/admin/users/{$other->id}/vendor", ['vendor_id' => $vendor->id])
            ->assertUnprocessable()
            ->assertJsonPath('errors.vendor_id.0', 'Revoke the System Administrator role first.');

        $other->refresh();
        $this->assertNull($other->vendor_id);
        $this->assertSame('internal', $other->access->value);
    }

    public function test_unlinking_is_unaffected(): void
    {
        $staff = User::factory()->vendorStaff(Vendor::factory()->create())->create();

        $this->actingAs($this->admin)
            ->putJson("/api/admin/users/{$staff->id}/vendor", ['vendor_id' => null])
            ->assertSuccessful();
    }

    public function test_the_check_command_lists_vendor_admins_sorted_and_changes_nothing(): void
    {
        $vendor = Vendor::factory()->create(['name' => 'Acme Vendor']);
        $b = User::factory()->vendorStaff($vendor)->create(['employee_id' => 'EMP-B', 'name' => 'Bea']);
        $a = User::factory()->vendorStaff($vendor)->create(['employee_id' => 'EMP-A', 'name' => 'Abe']);
        $viewer = User::factory()->vendorStaff($vendor)->create(['employee_id' => 'EMP-C', 'name' => 'Cy']);
        foreach ([$b, $a] as $u) {
            $u->roles()->create(['role' => PssRole::SystemAdministrator]);
        }
        $viewer->grantRole(PssRole::ManagementViewer);
        $before = [User::query()->orderBy('id')->get()->toArray(), UserRole::query()->orderBy('id')->get()->toArray()];

        $this->artisan('pss:check-vendor-admins')
            ->expectsOutputToContain('EMP-A | Abe | Acme Vendor')
            ->expectsOutputToContain('EMP-B | Bea | Acme Vendor')
            ->doesntExpectOutputToContain('EMP-C')
            ->doesntExpectOutputToContain('EMP-ADMIN')
            ->assertSuccessful();

        $this->assertEquals($before, [User::query()->orderBy('id')->get()->toArray(), UserRole::query()->orderBy('id')->get()->toArray()]);
    }

    public function test_the_check_command_says_none_found_and_exits_0(): void
    {
        $this->artisan('pss:check-vendor-admins')
            ->expectsOutputToContain('No vendor-linked System Administrators found.')
            ->assertSuccessful();
    }
}
