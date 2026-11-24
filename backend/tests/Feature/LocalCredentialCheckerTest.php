<?php

namespace Tests\Feature;

use App\Auth\CredentialChecker;
use App\Auth\LocalCredentialChecker;
use App\Auth\LocalCredentialCheckerNotAllowed;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The local credential checker (issue 04): hashed passwords on users, allowed only in local and testing.
 */
class LocalCredentialCheckerTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_credential_checker_port_resolves_to_the_local_checker_in_testing(): void
    {
        $this->assertInstanceOf(LocalCredentialChecker::class, $this->app->make(CredentialChecker::class));
    }

    public function test_it_accepts_the_right_password_for_the_employee(): void
    {
        User::factory()->create(['employee_id' => 'EMP-1', 'password' => 'right-password']);

        $this->assertTrue($this->app->make(LocalCredentialChecker::class)->isValid('EMP-1', 'right-password'));
    }

    public function test_it_refuses_a_wrong_password_an_unknown_employee_and_a_user_without_a_password(): void
    {
        User::factory()->create(['employee_id' => 'EMP-1', 'password' => 'right-password']);
        User::factory()->create(['employee_id' => 'EMP-2', 'password' => null]);
        $checker = $this->app->make(LocalCredentialChecker::class);

        $this->assertFalse($checker->isValid('EMP-1', 'wrong-password'));
        $this->assertFalse($checker->isValid('EMP-UNKNOWN', 'right-password'));
        $this->assertFalse($checker->isValid('EMP-2', ''));
    }

    public function test_the_login_endpoint_uses_it(): void
    {
        User::factory()->create(['employee_id' => 'EMP-1', 'password' => 'right-password']);

        $this->postJson('/api/auth/login', ['employee_id' => 'EMP-1', 'password' => 'wrong-password'])->assertUnauthorized();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function refusedEnvironments(): array
    {
        return [
            'production' => ['production'],
            'staging' => ['staging'],
        ];
    }

    #[DataProvider('refusedEnvironments')]
    public function test_resolving_it_outside_local_and_testing_throws_a_clear_exception(string $environment): void
    {
        $this->app['env'] = $environment;

        $this->expectException(LocalCredentialCheckerNotAllowed::class);
        $this->expectExceptionMessage("The local credential checker is not allowed in the [{$environment}] environment");

        $this->app->make(CredentialChecker::class);
    }

    public function test_it_resolves_in_local(): void
    {
        $this->app['env'] = 'local';

        $this->assertInstanceOf(LocalCredentialChecker::class, $this->app->make(LocalCredentialChecker::class));
    }
}
