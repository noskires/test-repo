<?php

namespace Tests\Feature;

use App\Enums\PssRole;
use App\Enums\UserAccess;
use App\MasterData\Data\Employee;
use App\MasterData\InMemoryMasterDataDirectory;
use App\MasterData\MasterDataDirectory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `php artisan pss:setup-first-admin` (issue 04): the first System Administrator comes from PSS_FIRST_ADMIN_EMPLOYEE_ID.
 */
class SetupFirstAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var InMemoryMasterDataDirectory $directory */
        $directory = $this->app->make(MasterDataDirectory::class);
        $directory->addEmployee(new Employee(id: 'EMP-ADMIN', name: 'Ada Admin'));
    }

    public function test_it_creates_the_user_and_grants_system_administrator(): void
    {
        config(['pss.first_admin_employee_id' => 'EMP-ADMIN']);

        $this->artisan('pss:setup-first-admin')->assertSuccessful();

        $user = User::query()->where('employee_id', 'EMP-ADMIN')->sole();
        $this->assertSame('Ada Admin', $user->name);
        $this->assertNull($user->vendor_id);
        $this->assertSame(['system-administrator'], $user->roleKeys());
    }

    public function test_the_user_it_creates_is_internal(): void
    {
        config(['pss.first_admin_employee_id' => 'EMP-ADMIN']);

        $this->artisan('pss:setup-first-admin')->assertSuccessful();

        $this->assertSame(UserAccess::Internal, User::query()->where('employee_id', 'EMP-ADMIN')->sole()->access);
    }

    public function test_an_existing_pending_user_keeps_their_access_and_the_command_warns(): void
    {
        config(['pss.first_admin_employee_id' => 'EMP-ADMIN']);
        $existing = User::factory()->pending()->create(['employee_id' => 'EMP-ADMIN']);

        $this->artisan('pss:setup-first-admin')
            ->expectsOutputToContain('This user is pending')
            ->assertSuccessful();

        $this->assertSame(UserAccess::Pending, $existing->refresh()->access);
        $this->assertSame(['system-administrator'], $existing->roleKeys());
    }

    public function test_it_grants_the_role_to_an_existing_user(): void
    {
        config(['pss.first_admin_employee_id' => 'EMP-ADMIN']);
        $existing = User::factory()->create(['employee_id' => 'EMP-ADMIN']);
        $existing->grantRole(PssRole::ManagementViewer);

        $this->artisan('pss:setup-first-admin')->assertSuccessful();

        $this->assertSame(1, User::query()->count());
        $this->assertSame(['management-viewer', 'system-administrator'], $existing->refresh()->roleKeys());
    }

    public function test_running_it_twice_changes_nothing_and_still_succeeds(): void
    {
        config(['pss.first_admin_employee_id' => 'EMP-ADMIN']);

        $this->artisan('pss:setup-first-admin')->assertSuccessful();
        $this->artisan('pss:setup-first-admin')->assertSuccessful();

        $this->assertSame(1, User::query()->count());
        $this->assertDatabaseCount('user_roles', 1);
    }

    public function test_it_fails_when_the_variable_is_empty(): void
    {
        config(['pss.first_admin_employee_id' => '']);

        $this->artisan('pss:setup-first-admin')
            ->expectsOutputToContain('PSS_FIRST_ADMIN_EMPLOYEE_ID is not set')
            ->assertFailed();

        $this->assertSame(0, User::query()->count());
    }

    public function test_it_fails_when_the_employee_is_not_in_master_data(): void
    {
        config(['pss.first_admin_employee_id' => 'EMP-GHOST']);

        $this->artisan('pss:setup-first-admin')
            ->expectsOutputToContain('Employee [EMP-GHOST] was not found in the master data')
            ->assertFailed();

        $this->assertSame(0, User::query()->count());
        $this->assertDatabaseCount('user_roles', 0);
    }
}
