<?php

namespace Tests\Feature;

use App\Enums\PssRole;
use App\Http\Middleware\RequirePasswordChange;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route as RouteFacade;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * Temporary passwords, changing your own password and an admin reset (issue 44, ADR 0004 amendment 2026-11-24).
 */
class PasswordApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'employee_id' => 'EMP-1',
            'email' => 'rina@example.test',
            'password' => 'Current-Pass-1',
        ]);
    }

    private function mustChange(User $user): User
    {
        $user->forceFill(['must_change_password' => true])->save();

        return $user;
    }

    private function forgetSignedInUser(): void
    {
        $this->app['tymon.jwt']->unsetToken();
        $this->app['auth']->forgetGuards();
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function blockedRoutes(): array
    {
        return [
            'a record list (e-calls)' => ['GET', '/api/e-calls'],
            'a write (filing an e-call)' => ['POST', '/api/e-calls'],
            'the dashboard' => ['GET', '/api/dashboard'],
            'the admin api' => ['GET', '/api/admin/users'],
        ];
    }

    #[DataProvider('blockedRoutes')]
    public function test_a_user_with_a_temporary_password_is_blocked_from_every_other_route(string $method, string $uri): void
    {
        $this->mustChange($this->user)->grantRole(PssRole::SystemAdministrator);

        $this->actingAs($this->user)
            ->json($method, $uri)
            ->assertForbidden()
            ->assertExactJson(['message' => 'Change your temporary password first.']);
    }

    public function test_a_user_with_a_temporary_password_can_still_see_me_refresh_and_log_out(): void
    {
        $this->mustChange($this->user);

        $this->actingAs($this->user)->getJson('/api/auth/me')->assertOk()->assertJsonPath('must_change_password', true);

        $token = JWTAuth::fromUser($this->user);
        $this->forgetSignedInUser();
        $newToken = $this->withToken($token)->postJson('/api/auth/refresh')->assertOk()->json('access_token');
        $this->forgetSignedInUser();
        $this->withToken($newToken)->postJson('/api/auth/logout')->assertNoContent();
    }

    public function test_every_authenticated_api_route_runs_the_temporary_password_block(): void
    {
        $unguarded = collect(RouteFacade::getRoutes()->getRoutes())
            ->filter(fn (Route $route): bool => in_array('auth:api', $route->gatherMiddleware(), true))
            ->reject(fn (Route $route): bool => in_array(RequirePasswordChange::class, $route->gatherMiddleware(), true))
            ->map(fn (Route $route): string => $route->uri())
            ->values()
            ->all();

        $this->assertSame([], $unguarded);
    }

    public function test_changing_the_password_stores_the_new_hash_and_clears_the_temporary_flag(): void
    {
        $this->mustChange($this->user);

        $this->actingAs($this->user)
            ->postJson('/api/auth/password', [
                'current_password' => 'Current-Pass-1',
                'password' => 'Brand-New-Pass-2',
                'password_confirmation' => 'Brand-New-Pass-2',
            ])
            ->assertOk()
            ->assertJsonPath('email', 'rina@example.test')
            ->assertJsonPath('must_change_password', false);

        $this->user->refresh();
        $this->assertFalse($this->user->must_change_password);
        $this->assertTrue(Hash::check('Brand-New-Pass-2', $this->user->getAuthPassword()));

        $this->actingAs($this->user)->getJson('/api/dashboard')->assertOk();
        $this->postJson('/api/auth/login', ['email' => 'rina@example.test', 'password' => 'Brand-New-Pass-2'])
            ->assertOk()
            ->assertJsonPath('must_change_password', false);
    }

    public function test_a_user_who_has_no_temporary_password_can_change_theirs_too(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/auth/password', [
                'current_password' => 'Current-Pass-1',
                'password' => 'Brand-New-Pass-2',
                'password_confirmation' => 'Brand-New-Pass-2',
            ])
            ->assertOk();

        $this->assertTrue(Hash::check('Brand-New-Pass-2', $this->user->refresh()->getAuthPassword()));
    }

    public function test_a_pending_user_can_change_their_password(): void
    {
        $pending = $this->mustChange(User::factory()->pending()->create(['password' => 'Current-Pass-1']));

        $this->actingAs($pending)
            ->postJson('/api/auth/password', [
                'current_password' => 'Current-Pass-1',
                'password' => 'Brand-New-Pass-2',
                'password_confirmation' => 'Brand-New-Pass-2',
            ])
            ->assertOk();

        $this->assertFalse($pending->refresh()->must_change_password);
    }

    /**
     * @return array<string, array{array<string, string>, string}>
     */
    public static function invalidChanges(): array
    {
        return [
            'wrong current password' => [['current_password' => 'Wrong-Pass-9', 'password' => 'Brand-New-Pass-2', 'password_confirmation' => 'Brand-New-Pass-2'], 'current_password'],
            'missing current password' => [['password' => 'Brand-New-Pass-2', 'password_confirmation' => 'Brand-New-Pass-2'], 'current_password'],
            'same as the current password' => [['current_password' => 'Current-Pass-1', 'password' => 'Current-Pass-1', 'password_confirmation' => 'Current-Pass-1'], 'password'],
            'confirmation does not match' => [['current_password' => 'Current-Pass-1', 'password' => 'Brand-New-Pass-2', 'password_confirmation' => 'Other-Pass-3'], 'password'],
            'too short' => [['current_password' => 'Current-Pass-1', 'password' => 'short', 'password_confirmation' => 'short'], 'password'],
            'missing new password' => [['current_password' => 'Current-Pass-1'], 'password'],
        ];
    }

    /**
     * @param  array<string, string>  $payload
     */
    #[DataProvider('invalidChanges')]
    public function test_an_invalid_change_gives_422_and_changes_nothing(array $payload, string $errorKey): void
    {
        $this->mustChange($this->user);

        $this->actingAs($this->user)
            ->postJson('/api/auth/password', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$errorKey]);

        $this->user->refresh();
        $this->assertTrue($this->user->must_change_password);
        $this->assertTrue(Hash::check('Current-Pass-1', $this->user->getAuthPassword()));
    }

    public function test_changing_the_password_needs_a_token(): void
    {
        $this->postJson('/api/auth/password', [])->assertUnauthorized();
    }

    public function test_an_admin_reset_sets_a_new_temporary_password(): void
    {
        $admin = User::factory()->create(['email' => 'ada@example.test']);
        $admin->grantRole(PssRole::SystemAdministrator);

        $this->actingAs($admin)
            ->postJson("/api/admin/users/{$this->user->id}/password", ['temporary_password' => 'Reset-Pass-123'])
            ->assertOk()
            ->assertJsonPath('data.id', $this->user->id)
            ->assertJsonPath('data.must_change_password', true)
            ->assertJsonMissingPath('data.password');

        $this->user->refresh();
        $this->assertTrue($this->user->must_change_password);
        $this->assertTrue(Hash::check('Reset-Pass-123', $this->user->getAuthPassword()));
        $this->postJson('/api/auth/login', ['email' => 'rina@example.test', 'password' => 'Current-Pass-1'])->assertUnauthorized();
        $this->postJson('/api/auth/login', ['email' => 'rina@example.test', 'password' => 'Reset-Pass-123'])
            ->assertOk()
            ->assertJsonPath('must_change_password', true);
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function invalidResets(): array
    {
        return [
            'missing' => [[]],
            'too short' => [['temporary_password' => 'short']],
            'not a string' => [['temporary_password' => 12345678]],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    #[DataProvider('invalidResets')]
    public function test_an_invalid_reset_gives_422_and_changes_nothing(array $payload): void
    {
        $admin = User::factory()->create();
        $admin->grantRole(PssRole::SystemAdministrator);

        $this->actingAs($admin)
            ->postJson("/api/admin/users/{$this->user->id}/password", $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['temporary_password']);

        $this->assertFalse($this->user->refresh()->must_change_password);
        $this->assertTrue(Hash::check('Current-Pass-1', $this->user->getAuthPassword()));
    }

    public function test_only_a_system_administrator_may_reset_a_password(): void
    {
        $viewer = User::factory()->create();
        $viewer->grantRole(PssRole::ManagementViewer);

        foreach ([User::factory()->create(), $viewer, User::factory()->vendorStaff()->create(), $this->user] as $user) {
            $this->actingAs($user)
                ->postJson("/api/admin/users/{$this->user->id}/password", ['temporary_password' => 'Reset-Pass-123'])
                ->assertForbidden();
        }

        $this->assertFalse($this->user->refresh()->must_change_password);
        $this->assertTrue(Hash::check('Current-Pass-1', $this->user->getAuthPassword()));
    }

    public function test_resetting_needs_a_token_and_an_existing_user(): void
    {
        $this->postJson("/api/admin/users/{$this->user->id}/password", ['temporary_password' => 'Reset-Pass-123'])->assertUnauthorized();

        $admin = User::factory()->create();
        $admin->grantRole(PssRole::SystemAdministrator);
        $this->actingAs($admin)
            ->postJson('/api/admin/users/999999/password', ['temporary_password' => 'Reset-Pass-123'])
            ->assertNotFound();
    }
}
