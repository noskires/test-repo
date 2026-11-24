<?php

namespace Tests\Feature;

use App\Enums\PssRole;
use App\Enums\UserAccess;
use App\MasterData\MasterDataDirectory;
use App\MasterData\SampleMasterData;
use App\Models\User;
use Database\Seeders\DemoAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Local demo accounts (issue 04): one of each kind of user, all known to the local dev master data (issue 30).
 */
class DemoAccountsSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        SampleMasterData::load($this->app->make(MasterDataDirectory::class));
    }

    public function test_it_seeds_an_admin_a_viewer_an_employee_and_a_linked_vendor_staff_user(): void
    {
        $this->seed(DemoAccountsSeeder::class);

        $admin = User::query()->where('employee_id', SampleMasterData::SYSTEM_ADMINISTRATOR_EMPLOYEE_ID)->sole();
        $viewer = User::query()->where('employee_id', SampleMasterData::MANAGEMENT_VIEWER_EMPLOYEE_ID)->sole();
        $employee = User::query()->where('employee_id', SampleMasterData::REQUESTOR_EMPLOYEE_ID)->sole();
        $vendorStaff = User::query()->where('employee_id', SampleMasterData::VENDOR_STAFF_EMPLOYEE_ID)->sole();

        $this->assertSame([PssRole::SystemAdministrator->value], $admin->roleKeys());
        $this->assertSame([PssRole::ManagementViewer->value], $viewer->roleKeys());
        $this->assertSame([], $employee->roleKeys());
        $this->assertSame([], $vendorStaff->roleKeys());
        $this->assertNull($admin->vendor_id);
        $this->assertNull($viewer->vendor_id);
        $this->assertNull($employee->vendor_id);
        $this->assertNotNull($vendorStaff->vendor);
        $this->assertSame(UserAccess::Internal, $admin->access);
        $this->assertSame(UserAccess::Internal, $viewer->access);
        $this->assertSame(UserAccess::Internal, $employee->access);
        $this->assertSame(UserAccess::Vendor, $vendorStaff->access);
    }

    public function test_every_demo_account_is_in_the_master_data_and_can_sign_in_with_the_known_password(): void
    {
        $this->seed(DemoAccountsSeeder::class);
        $directory = $this->app->make(MasterDataDirectory::class);

        $this->assertSame(4, User::query()->count());
        foreach (User::query()->get() as $user) {
            $this->assertSame($directory->findEmployee($user->employee_id)?->name, $user->name);

            $this->postJson('/api/auth/login', [
                'employee_id' => $user->employee_id,
                'password' => DemoAccountsSeeder::PASSWORD,
            ])->assertOk();
        }
    }

    public function test_seeding_twice_creates_no_duplicates(): void
    {
        $this->seed(DemoAccountsSeeder::class);
        $this->seed(DemoAccountsSeeder::class);

        $this->assertSame(4, User::query()->count());
        $this->assertDatabaseCount('user_roles', 2);
        $this->assertDatabaseCount('vendors', 1);
    }
}
