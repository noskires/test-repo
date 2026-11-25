<?php

namespace Tests\Feature;

use App\Enums\PssRole;
use App\Enums\UserAccess;
use App\MasterData\Data\Employee;
use App\MasterData\InMemoryMasterDataDirectory;
use App\MasterData\MasterDataDirectory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * `php artisan pss:setup-first-admin` (issues 04 and 44): the first System Administrator comes from
 * PSS_FIRST_ADMIN_EMPLOYEE_ID and signs in with PSS_FIRST_ADMIN_EMAIL (or --email) and a temporary password from
 * PSS_FIRST_ADMIN_TEMPORARY_PASSWORD.
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

        config([
            'pss.first_admin_email' => 'ada@example.test',
            'pss.first_admin_temporary_password' => 'First-Admin-Pass-1',
        ]);
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

    public function test_the_new_admin_gets_the_email_and_must_change_the_temporary_password(): void
    {
        config(['pss.first_admin_employee_id' => 'EMP-ADMIN']);

        $this->artisan('pss:setup-first-admin')
            ->expectsOutputToContain('ada@example.test')
            ->assertSuccessful();

        $user = User::query()->where('employee_id', 'EMP-ADMIN')->sole();
        $this->assertSame('ada@example.test', $user->email);
        $this->assertTrue($user->must_change_password);
        $this->assertTrue(Hash::check('First-Admin-Pass-1', $user->getAuthPassword()));

        $this->postJson('/api/auth/login', ['email' => 'ada@example.test', 'password' => 'First-Admin-Pass-1'])
            ->assertOk()
            ->assertJsonPath('must_change_password', true);
    }

    public function test_the_email_option_wins_over_the_environment_variable(): void
    {
        config(['pss.first_admin_employee_id' => 'EMP-ADMIN']);

        $this->artisan('pss:setup-first-admin', ['--email' => ' Boss@Example.test '])->assertSuccessful();

        $this->assertSame('boss@example.test', User::query()->where('employee_id', 'EMP-ADMIN')->sole()->email);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidSignInDetails(): array
    {
        return [
            'no email' => [['pss.first_admin_email' => null], 'PSS_FIRST_ADMIN_EMAIL'],
            'invalid email' => [['pss.first_admin_email' => 'not-an-email'], 'not a valid email'],
            'no temporary password' => [['pss.first_admin_temporary_password' => null], 'PSS_FIRST_ADMIN_TEMPORARY_PASSWORD is not set'],
            'temporary password too short' => [['pss.first_admin_temporary_password' => 'short'], 'PSS_FIRST_ADMIN_TEMPORARY_PASSWORD'],
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    #[DataProvider('invalidSignInDetails')]
    public function test_it_fails_and_stores_nothing_without_valid_sign_in_details(array $config, string $message): void
    {
        config(['pss.first_admin_employee_id' => 'EMP-ADMIN', ...$config]);

        $this->artisan('pss:setup-first-admin')
            ->expectsOutputToContain($message)
            ->assertFailed();

        $this->assertSame(0, User::query()->count());
        $this->assertDatabaseCount('user_roles', 0);
    }

    public function test_it_fails_when_another_user_already_has_the_email(): void
    {
        config(['pss.first_admin_employee_id' => 'EMP-ADMIN']);
        User::factory()->create(['employee_id' => 'EMP-OTHER', 'email' => 'ada@example.test']);

        $this->artisan('pss:setup-first-admin')
            ->expectsOutputToContain('already used by another user')
            ->assertFailed();

        $this->assertNull(User::query()->firstWhere('employee_id', 'EMP-ADMIN'));
        $this->assertDatabaseCount('user_roles', 0);
    }

    public function test_an_existing_user_without_an_email_gets_the_email_and_temporary_password(): void
    {
        config(['pss.first_admin_employee_id' => 'EMP-ADMIN']);
        $existing = User::factory()->create(['employee_id' => 'EMP-ADMIN', 'email' => null, 'password' => null]);

        $this->artisan('pss:setup-first-admin')->assertSuccessful();

        $existing->refresh();
        $this->assertSame('ada@example.test', $existing->email);
        $this->assertTrue($existing->must_change_password);
        $this->assertTrue(Hash::check('First-Admin-Pass-1', $existing->getAuthPassword()));
    }

    /**
     * Running it again never resets a password the admin has already changed.
     */
    public function test_an_existing_user_with_an_email_keeps_their_email_and_password(): void
    {
        config(['pss.first_admin_employee_id' => 'EMP-ADMIN']);
        $existing = User::factory()->create(['employee_id' => 'EMP-ADMIN', 'email' => 'old@example.test', 'password' => 'Own-Pass-77']);

        $this->artisan('pss:setup-first-admin', ['--email' => 'new@example.test'])
            ->expectsOutputToContain('keeps their email [old@example.test] and password')
            ->assertSuccessful();

        $existing->refresh();
        $this->assertSame('old@example.test', $existing->email);
        $this->assertFalse($existing->must_change_password);
        $this->assertTrue(Hash::check('Own-Pass-77', $existing->getAuthPassword()));
        $this->assertSame(['system-administrator'], $existing->roleKeys());
    }

    public function test_an_existing_user_with_an_email_needs_no_sign_in_details(): void
    {
        config([
            'pss.first_admin_employee_id' => 'EMP-ADMIN',
            'pss.first_admin_email' => null,
            'pss.first_admin_temporary_password' => null,
        ]);
        $existing = User::factory()->create(['employee_id' => 'EMP-ADMIN', 'email' => 'old@example.test']);

        $this->artisan('pss:setup-first-admin')->assertSuccessful();

        $this->assertSame(['system-administrator'], $existing->refresh()->roleKeys());
    }
}
