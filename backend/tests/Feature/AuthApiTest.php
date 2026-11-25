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
 * JWT sign-in (issue 04, ADR 0004/0005): login, refresh, logout and me. Sign-in only signs in users an administrator
 * added (issue 46); it never creates one.
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
        User::factory()->create(['employee_id' => 'EMP-1', 'name' => 'Rina Requestor', 'email' => 'rina@example.test']);
    }

    /**
     * Bind a credential checker that accepts exactly these email and password pairs.
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

            public function isValid(string $email, string $password): bool
            {
                return ($this->passwords[$email] ?? null) === $password;
            }
        });
    }

    private function login(string $email = 'rina@example.test', string $password = 'secret'): string
    {
        return $this->postJson('/api/auth/login', ['email' => $email, 'password' => $password])
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
        $this->acceptCredentials(['rina@example.test' => 'secret']);

        $this->postJson('/api/auth/login', ['email' => 'rina@example.test', 'password' => 'secret'])
            ->assertOk()
            ->assertJsonStructure(['access_token', 'token_type', 'expires_in', 'must_change_password'])
            ->assertJsonPath('token_type', 'bearer')
            ->assertJsonPath('expires_in', 3600)
            ->assertJsonPath('must_change_password', false);
    }

    /**
     * The password is checked first (Q16): a wrong one gives the same 401 whether or not the email belongs to a PSS
     * user, and an unknown email gives that same 401.
     */
    public function test_wrong_credentials_give_the_same_401_for_known_and_unknown_emails(): void
    {
        $this->acceptCredentials(['rina@example.test' => 'secret']);

        $known = $this->postJson('/api/auth/login', ['email' => 'rina@example.test', 'password' => 'wrong'])
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Invalid email or password.']);
        $unknown = $this->postJson('/api/auth/login', ['email' => 'nobody@example.test', 'password' => 'secret'])
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Invalid email or password.']);

        $this->assertSame($known->getContent(), $unknown->getContent());
        $this->assertSame(1, User::query()->count());
    }

    /**
     * Only reachable with a checker other than the local one: correct credentials for an email with no PSS user.
     */
    public function test_correct_credentials_without_a_pss_user_give_403_no_token_and_create_no_user(): void
    {
        $this->acceptCredentials(['ghost@example.test' => 'secret']);

        $this->postJson('/api/auth/login', ['email' => 'ghost@example.test', 'password' => 'secret'])
            ->assertForbidden()
            ->assertExactJson(['message' => "Your account isn't set up in PSS yet. Ask an administrator."]);

        $this->assertSame(1, User::query()->count());
    }

    public function test_the_email_is_matched_without_regard_to_case_or_surrounding_spaces(): void
    {
        $this->acceptCredentials(['rina@example.test' => 'secret']);

        $this->login('  Rina@Example.TEST ');
    }

    public function test_a_user_who_must_change_their_password_is_told_so_at_login(): void
    {
        User::query()->where('employee_id', 'EMP-1')->sole()->forceFill(['must_change_password' => true])->save();
        $this->acceptCredentials(['rina@example.test' => 'secret']);

        $this->postJson('/api/auth/login', ['email' => 'rina@example.test', 'password' => 'secret'])
            ->assertOk()
            ->assertJsonPath('must_change_password', true);
    }

    public function test_login_never_calls_the_master_data_directory(): void
    {
        $this->acceptCredentials(['rina@example.test' => 'secret']);
        $this->app->instance(MasterDataDirectory::class, $this->createMock(MasterDataDirectory::class));
        $this->app->make(MasterDataDirectory::class)->expects($this->never())->method($this->anything());

        $this->login();
    }

    public function test_a_pending_user_with_a_correct_password_gets_a_token(): void
    {
        User::factory()->pending()->create(['employee_id' => 'EMP-P', 'email' => 'paula@example.test']);
        $this->acceptCredentials(['paula@example.test' => 'secret']);

        $token = $this->login('paula@example.test');
        $this->forgetSignedInUser();

        $this->withToken($token)->getJson('/api/auth/me')->assertOk()->assertJsonPath('access', 'pending');
    }

    public function test_missing_fields_give_422(): void
    {
        $this->acceptCredentials(['rina@example.test' => 'secret']);

        $this->postJson('/api/auth/login', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_an_invalid_email_gives_422(): void
    {
        $this->acceptCredentials(['rina@example.test' => 'secret']);

        $this->postJson('/api/auth/login', ['email' => 'not-an-email', 'password' => 'secret'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }

    public function test_the_employee_id_is_no_longer_what_users_sign_in_with(): void
    {
        $this->acceptCredentials(['rina@example.test' => 'secret']);

        $this->postJson('/api/auth/login', ['employee_id' => 'EMP-1', 'password' => 'secret'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }

    public function test_the_token_authenticates_requests_until_it_expires_after_60_minutes(): void
    {
        $this->acceptCredentials(['rina@example.test' => 'secret']);
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
        $this->acceptCredentials(['rina@example.test' => 'secret']);
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
        $this->acceptCredentials(['rina@example.test' => 'secret']);
        $token = $this->login();

        $this->travel(14)->days();
        $this->travel(-1)->minutes();
        $this->forgetSignedInUser();

        $this->withToken($token)->postJson('/api/auth/refresh')->assertOk()->assertJsonStructure(['access_token']);
    }

    public function test_refresh_gives_401_after_the_14_day_window(): void
    {
        $this->acceptCredentials(['rina@example.test' => 'secret']);
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
        $this->acceptCredentials(['rina@example.test' => 'secret']);
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
        $this->acceptCredentials(['rina@example.test' => 'secret']);
        $token = $this->login();
        User::query()->where('employee_id', 'EMP-1')->sole()->grantRole(PssRole::ManagementViewer);
        $this->forgetSignedInUser();

        $this->withToken($token)->getJson('/api/auth/me')
            ->assertOk()
            ->assertExactJson([
                'employee_id' => 'EMP-1',
                'email' => 'rina@example.test',
                'name' => 'Rina Requestor',
                'roles' => ['management-viewer'],
                'vendor_id' => null,
                'access' => 'internal',
                'must_change_password' => false,
            ]);
    }

    public function test_me_reads_the_vendor_link_from_the_database_on_each_request(): void
    {
        $this->acceptCredentials(['rina@example.test' => 'secret']);
        $token = $this->login();
        $vendor = Vendor::factory()->create();
        User::query()->where('employee_id', 'EMP-1')->sole()->forceFill(['vendor_id' => $vendor->id])->save();
        $this->forgetSignedInUser();

        $this->withToken($token)->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('vendor_id', $vendor->id)
            ->assertJsonPath('access', 'vendor');
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
        $this->acceptCredentials(['rina@example.test' => 'secret']);
        $token = $this->login();
        $this->forgetSignedInUser();

        $this->withToken($token)->getJson('/api/dashboard')->assertOk();
    }

    public function test_sign_in_never_changes_the_user(): void
    {
        $vendor = Vendor::factory()->create();
        User::query()->where('employee_id', 'EMP-1')->sole()->forceFill(['vendor_id' => $vendor->id, 'name' => 'Old Name'])->save();
        $this->acceptCredentials(['rina@example.test' => 'secret']);

        $this->login();
        $this->login();

        $user = User::query()->where('employee_id', 'EMP-1')->sole();
        $this->assertSame($vendor->id, $user->vendor_id);
        $this->assertSame('Old Name', $user->name);
        $this->assertSame(1, User::query()->count());
    }
}
