<?php

namespace Tests\Feature;

use App\Enums\PssRole;
use App\Enums\UserAccess;
use App\MasterData\MasterDataDirectory;
use App\MasterData\SampleMasterData;
use App\Models\SiteSetting;
use App\Models\User;
use App\Models\Vendor;
use Database\Seeders\DemoAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Local demo accounts (issues 04 and 44): one sign-in-able account per kind of user, all known to the local dev master
 * data (issue 30), plus the Site settings that make the EIC and the vendor staff accounts usable.
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

        $this->assertSame(count(DemoAccountsSeeder::EMAILS), User::query()->count());
        foreach (User::query()->get() as $user) {
            $this->assertSame($directory->findEmployee($user->employee_id)?->name, $user->name);

            $this->assertSame(DemoAccountsSeeder::EMAILS[$user->employee_id], $user->email);
            $this->assertFalse($user->must_change_password);

            $this->postJson('/api/auth/login', [
                'email' => $user->email,
                'password' => DemoAccountsSeeder::PASSWORD,
            ])->assertOk()->assertJsonPath('must_change_password', false);
        }
    }

    public function test_seeding_twice_creates_no_duplicates(): void
    {
        $this->seed(DemoAccountsSeeder::class);
        $this->seed(DemoAccountsSeeder::class);

        $this->assertSame(count(DemoAccountsSeeder::EMAILS), User::query()->count());
        $this->assertDatabaseCount('user_roles', 2);
        $this->assertDatabaseCount('vendors', 2);
        $this->assertDatabaseCount('site_settings', 2);
        $this->assertDatabaseCount('site_assignment_changes', 4);
    }

    /**
     * Added scope (human, 2026-11-25): one account per role, on readable .test emails.
     */
    public function test_there_is_one_account_per_role_on_a_readable_test_email(): void
    {
        $this->assertSame([
            SampleMasterData::SYSTEM_ADMINISTRATOR_EMPLOYEE_ID => 'admin@pss.test',
            SampleMasterData::MANAGEMENT_VIEWER_EMPLOYEE_ID => 'viewer@pss.test',
            SampleMasterData::REQUESTOR_EMPLOYEE_ID => 'requestor@pss.test',
            'EMP-2001' => 'supervisor@pss.test',
            'EMP-3001' => 'divhead@pss.test',
            'EMP-4001' => 'centerhead@pss.test',
            'EMP-5001' => 'sectorhead@pss.test',
            SampleMasterData::EIC_EMPLOYEE_ID => 'eic@pss.test',
            SampleMasterData::VENDOR_STAFF_EMPLOYEE_ID => 'vendor1@pss.test',
            SampleMasterData::SECOND_VENDOR_STAFF_EMPLOYEE_ID => 'vendor2@pss.test',
        ], DemoAccountsSeeder::EMAILS);
    }

    public function test_the_approver_chain_accounts_are_internal_employees_on_the_requestors_immediate_head_chain(): void
    {
        $this->seed(DemoAccountsSeeder::class);
        $directory = $this->app->make(MasterDataDirectory::class);

        $chain = [];
        $employeeId = SampleMasterData::REQUESTOR_EMPLOYEE_ID;
        while (($employeeId = $directory->findEmployee($employeeId)?->immediateHeadId) !== null) {
            $chain[] = $employeeId;
        }
        $this->assertSame(['EMP-2001', 'EMP-3001', 'EMP-4001', 'EMP-5001'], $chain);

        foreach ($chain as $headId) {
            $head = User::query()->where('employee_id', $headId)->sole();
            $this->assertTrue($head->isInternalEmployee(), $headId);
            $this->assertSame([], $head->roleKeys());
        }
    }

    public function test_the_eic_account_is_the_acting_eic_of_every_sample_site(): void
    {
        $this->seed(DemoAccountsSeeder::class);
        $eic = User::query()->where('employee_id', SampleMasterData::EIC_EMPLOYEE_ID)->sole();

        $this->assertSame(UserAccess::Internal, $eic->access);
        foreach (['SITE-MKT', 'SITE-CEB'] as $siteId) {
            $this->assertTrue(SiteSetting::isActingEic($siteId, $eic), $siteId);
        }

        $this->postJson('/api/auth/login', ['email' => 'eic@pss.test', 'password' => DemoAccountsSeeder::PASSWORD])->assertOk();
    }

    public function test_each_demo_vendor_has_its_own_staff_account_and_site(): void
    {
        $this->seed(DemoAccountsSeeder::class);

        $vendorOne = Vendor::query()->where('name', DemoAccountsSeeder::DEMO_VENDOR_NAME)->sole();
        $vendorTwo = Vendor::query()->where('name', DemoAccountsSeeder::SECOND_DEMO_VENDOR_NAME)->sole();

        $this->assertSame($vendorOne->id, User::query()->where('email', 'vendor1@pss.test')->sole()->vendor_id);
        $this->assertSame($vendorTwo->id, User::query()->where('email', 'vendor2@pss.test')->sole()->vendor_id);
        $this->assertSame($vendorOne->id, SiteSetting::query()->where('site_id', 'SITE-MKT')->sole()->vendor_id);
        $this->assertSame($vendorTwo->id, SiteSetting::query()->where('site_id', 'SITE-CEB')->sole()->vendor_id);
    }

    public function test_seeding_again_leaves_the_site_settings_and_passwords_as_they_are(): void
    {
        $this->seed(DemoAccountsSeeder::class);
        $before = SiteSetting::query()->orderBy('site_id')->get(['site_id', 'vendor_id', 'eic_employee_id'])->toArray();

        $this->seed(DemoAccountsSeeder::class);

        $this->assertSame($before, SiteSetting::query()->orderBy('site_id')->get(['site_id', 'vendor_id', 'eic_employee_id'])->toArray());
        $this->postJson('/api/auth/login', ['email' => 'admin@pss.test', 'password' => DemoAccountsSeeder::PASSWORD])
            ->assertOk()
            ->assertJsonPath('must_change_password', false);
    }
}
