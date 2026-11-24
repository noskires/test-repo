<?php

namespace Tests\Feature;

use App\Enums\PssRole;
use App\Http\Middleware\BlockPendingUsers;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * A pending user (issue 46, Q5) can sign in, see who they are, refresh and sign out, and nothing else.
 */
class PendingUserAccessTest extends TestCase
{
    use RefreshDatabase;

    private User $pending;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pending = User::factory()->pending()->create(['employee_id' => 'EMP-P', 'name' => 'Paula Pending']);
    }

    public function test_me_works_and_returns_pending_access(): void
    {
        $this->actingAs($this->pending)
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertExactJson([
                'employee_id' => 'EMP-P',
                'name' => 'Paula Pending',
                'roles' => [],
                'vendor_id' => null,
                'access' => 'pending',
            ]);
    }

    public function test_refresh_and_logout_work(): void
    {
        $token = JWTAuth::fromUser($this->pending);

        $newToken = $this->withToken($token)->postJson('/api/auth/refresh')->assertOk()->json('access_token');
        $this->app['tymon.jwt']->unsetToken();
        $this->app['auth']->forgetGuards();

        $this->withToken($newToken)->postJson('/api/auth/logout')->assertNoContent();
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function blockedRoutes(): array
    {
        return [
            'a record list (vendor pm tasks)' => ['GET', '/api/vendor/pm-tasks'],
            'a record list (e-calls)' => ['GET', '/api/e-calls'],
            'a write (filing an e-call)' => ['POST', '/api/e-calls'],
            'the dashboard' => ['GET', '/api/dashboard'],
            'the waiting list' => ['GET', '/api/me/waiting'],
            'the admin api' => ['GET', '/api/admin/users'],
        ];
    }

    #[DataProvider('blockedRoutes')]
    public function test_every_other_route_gives_403(string $method, string $uri): void
    {
        $this->actingAs($this->pending)
            ->json($method, $uri)
            ->assertForbidden();
    }

    #[DataProvider('blockedRoutes')]
    public function test_a_pending_user_who_still_holds_system_administrator_is_blocked_too(string $method, string $uri): void
    {
        $this->pending->roles()->create(['role' => PssRole::SystemAdministrator]);

        $this->actingAs($this->pending)
            ->json($method, $uri)
            ->assertForbidden()
            ->assertJsonPath('message', 'Your account is waiting for an administrator to set up your access.');
    }

    public function test_classified_users_are_not_blocked(): void
    {
        $this->actingAs(User::factory()->create(['employee_id' => 'EMP-I']))->getJson('/api/dashboard')->assertOk();
        $this->actingAs(User::factory()->vendorStaff()->create(['employee_id' => 'EMP-V']))->getJson('/api/vendor/pm-tasks')->assertOk();
    }

    public function test_every_authenticated_api_route_runs_the_pending_block(): void
    {
        $unguarded = collect(RouteFacade::getRoutes()->getRoutes())
            ->filter(fn (Route $route): bool => in_array('auth:api', $route->gatherMiddleware(), true))
            ->reject(fn (Route $route): bool => in_array(BlockPendingUsers::class, $route->gatherMiddleware(), true))
            ->map(fn (Route $route): string => $route->uri())
            ->values()
            ->all();

        $this->assertSame([], $unguarded);
    }
}
