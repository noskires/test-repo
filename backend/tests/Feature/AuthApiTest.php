<?php

namespace Tests\Feature;

use App\Auth\CredentialChecker;
use App\Enums\PssRole;
use App\MasterData\Data\Employee;
use App\MasterData\InMemoryMasterDataDirectory;
use App\MasterData\MasterDataDirectory;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * JWT sign-in (issue 04, ADR 0004): login, refresh, logout and me, with users created on first sign-in.
 */
class AuthApiTest extends TestCase
{
    use RefreshDatabase;

    private InMemoryMasterDataDirectory $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = $this->app->make(MasterDataDirectory::class);
        $this->directory->addEmployee(new Employee(id: 'EMP-1', name: 'Rina Requestor'));
    }

    /**
     * Bind a credential checker that accepts exactly these employee ID and password pairs.
     *
     * @param  array<string, string>  $passwords
     */
    private function acceptCredentials(array $passwords): void
    {
        $this->app->instance(CredentialChecker::class, new class($passwords) implements CredentialChecker
        {
            /**
             * @param  array<string, string>  $passwords
             */
            public function __construct(private array $passwords) {}

            public function isValid(string $employeeId, string $password): bool
            {
                return ($this->passwords[$employeeId] ?? null) === $password;
            }
        });
    }

    private function login(string $employeeId = 'EMP-1', string $password = 'secret'): string
    {
        return $this->postJson('/api/auth/login', ['employee_id' => $employeeId, 'password' => $password])
            ->assertOk()
            ->json('access_token');
    }

    /**
     * Drop the guard's resolved user and parsed token (both live for the whole test process), so the next request
     * authenticates from its own Bearer token alone, as a real request would.
     */
    private function forgetSignedInUser(): void
    {
        $this->app['tymon.jwt']->unsetToken();
        $this->app['auth']->forgetGuards();
    }

    public function test_login_returns_a_bearer_token_that_lives_60_minutes(): void
    {
        $this->acceptCredentials(['EMP-1' => 'secret']);

        $this->postJson('/api/auth/login', ['employee_id' => 'EMP-1', 'password' => 'secret'])
            ->assertOk()
            ->assertJsonStructure(['access_token', 'token_type', 'expires_in'])
            ->assertJsonPath('token_type', 'bearer')
            ->assertJsonPath('expires_in', 3600);
    }

    public function test_wrong_credentials_give_401_and_no_token(): void
    {
        $this->acceptCredentials(['EMP-1' => 'secret']);

        $this->postJson('/api/auth/login', ['employee_id' => 'EMP-1', 'password' => 'wrong'])
            ->assertUnauthorized()
            ->assertJsonMissingPath('access_token');

        $this->assertSame(0, User::query()->count());
    }

    public function test_missing_fields_give_422(): void
    {
        $this->acceptCredentials(['EMP-1' => 'secret']);

        $this->postJson('/api/auth/login', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['employee_id', 'password']);
    }

    public function test_the_token_authenticates_requests_until_it_expires_after_60_minutes(): void
    {
        $this->acceptCredentials(['EMP-1' => 'secret']);
        $token = $this->login();

        $this->travel(59)->minutes();
        $this->forgetSignedInUser();
        $this->withToken($token)->getJson('/api/auth/me')->assertOk();

        $this->travel(2)->minutes();
        $this->forgetSignedInUser();
        $this->withToken($token)->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_refresh_returns_a_new_token_for_a_valid_token(): void
    {
        $this->acceptCredentials(['EMP-1' => 'secret']);
        $token = $this->login();
        $this->forgetSignedInUser();

        $newToken = $this->withToken($token)->postJson('/api/auth/refresh')
            ->assertOk()
            ->assertJsonPath('token_type', 'bearer')
            ->assertJsonPath('expires_in', 3600)
            ->json('access_token');

        $this->assertNotSame($token, $newToken);
        $this->forgetSignedInUser();
        $this->withToken($newToken)->getJson('/api/auth/me')->assertOk()->assertJsonPath('employee_id', 'EMP-1');
    }

    public function test_refresh_accepts_an_expired_token_inside_the_14_day_window(): void
    {
        $this->acceptCredentials(['EMP-1' => 'secret']);
        $token = $this->login();

        $this->travel(14)->days();
        $this->travel(-1)->minutes();
        $this->forgetSignedInUser();

        $this->withToken($token)->postJson('/api/auth/refresh')->assertOk()->assertJsonStructure(['access_token']);
    }

    public function test_refresh_gives_401_after_the_14_day_window(): void
    {
        $this->acceptCredentials(['EMP-1' => 'secret']);
        $token = $this->login();

        $this->travel(14)->days();
        $this->travel(1)->minutes();
        $this->forgetSignedInUser();

        $this->withToken($token)->postJson('/api/auth/refresh')->assertUnauthorized();
    }

    public function test_refresh_without_a_token_gives_401(): void
    {
        $this->postJson('/api/auth/refresh')->assertUnauthorized();
    }

    public function test_logout_blacklists_the_token(): void
    {
        $this->acceptCredentials(['EMP-1' => 'secret']);
        $token = $this->login();
        $this->forgetSignedInUser();

        $this->withToken($token)->postJson('/api/auth/logout')->assertNoContent();

        $this->forgetSignedInUser();
        $this->withToken($token)->getJson('/api/auth/me')->assertUnauthorized();
        $this->forgetSignedInUser();
        $this->withToken($token)->postJson('/api/auth/refresh')->assertUnauthorized();
    }

    public function test_me_returns_the_signed_in_users_identity_roles_and_vendor(): void
    {
        $this->acceptCredentials(['EMP-1' => 'secret']);
        $token = $this->login();
        User::query()->where('employee_id', 'EMP-1')->sole()->grantRole(PssRole::ManagementViewer);
        $this->forgetSignedInUser();

        $this->withToken($token)->getJson('/api/auth/me')
            ->assertOk()
            ->assertExactJson([
                'employee_id' => 'EMP-1',
                'name' => 'Rina Requestor',
                'roles' => ['management-viewer'],
                'vendor_id' => null,
            ]);
    }

    public function test_me_reads_the_vendor_link_from_the_database_on_each_request(): void
    {
        $this->acceptCredentials(['EMP-1' => 'secret']);
        $token = $this->login();
        $vendor = Vendor::factory()->create();
        User::query()->where('employee_id', 'EMP-1')->sole()->forceFill(['vendor_id' => $vendor->id])->save();
        $this->forgetSignedInUser();

        $this->withToken($token)->getJson('/api/auth/me')->assertOk()->assertJsonPath('vendor_id', $vendor->id);
    }

    public function test_me_without_a_token_gives_401(): void
    {
        $this->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_the_api_rejects_a_garbage_token_with_401(): void
    {
        $this->withToken('not-a-jwt')->getJson('/api/dashboard')->assertUnauthorized();
    }

    public function test_the_api_routes_accept_the_jwt(): void
    {
        $this->acceptCredentials(['EMP-1' => 'secret']);
        $token = $this->login();
        $this->forgetSignedInUser();

        $this->withToken($token)->getJson('/api/dashboard')->assertOk();
    }

    public function test_first_sign_in_creates_a_user_with_the_master_data_name_no_roles_and_no_vendor(): void
    {
        $this->acceptCredentials(['EMP-1' => 'secret']);

        $this->login();

        $user = User::query()->where('employee_id', 'EMP-1')->sole();
        $this->assertSame('Rina Requestor', $user->name);
        $this->assertNull($user->vendor_id);
        $this->assertSame([], $user->roleKeys());
    }

    public function test_a_second_sign_in_reuses_the_same_user(): void
    {
        $this->acceptCredentials(['EMP-1' => 'secret']);

        $this->login();
        $this->login();

        $this->assertSame(1, User::query()->where('employee_id', 'EMP-1')->count());
    }

    public function test_sign_in_keeps_an_existing_vendor_link_and_never_sets_one(): void
    {
        $vendor = Vendor::factory()->create();
        User::factory()->vendorStaff($vendor)->create(['employee_id' => 'EMP-1', 'name' => 'Old Name']);
        $this->acceptCredentials(['EMP-1' => 'secret']);

        $this->login();

        $this->assertSame($vendor->id, User::query()->where('employee_id', 'EMP-1')->sole()->vendor_id);
    }

    public function test_accepted_credentials_for_an_employee_unknown_to_master_data_give_401_and_create_no_user(): void
    {
        $this->acceptCredentials(['EMP-GHOST' => 'secret']);

        $this->postJson('/api/auth/login', ['employee_id' => 'EMP-GHOST', 'password' => 'secret'])
            ->assertUnauthorized()
            ->assertJsonMissingPath('access_token');

        $this->assertSame(0, User::query()->count());
    }
}
