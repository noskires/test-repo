<?php

namespace Tests\Feature;

use App\Auth\CredentialChecker;
use App\Auth\LocalCredentialChecker;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The local credential checker (issues 04 and 44): email and hashed password on users, in every environment.
 */
class LocalCredentialCheckerTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_credential_checker_port_resolves_to_the_local_checker_in_testing(): void
    {
        $this->assertInstanceOf(LocalCredentialChecker::class, $this->app->make(CredentialChecker::class));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function environments(): array
    {
        return [
            'production' => ['production'],
            'staging' => ['staging'],
            'local' => ['local'],
        ];
    }

    #[DataProvider('environments')]
    public function test_the_port_resolves_to_the_local_checker_in_every_environment(string $environment): void
    {
        $this->app['env'] = $environment;

        $this->assertInstanceOf(LocalCredentialChecker::class, $this->app->make(CredentialChecker::class));
    }

    public function test_it_accepts_the_right_password_for_the_email(): void
    {
        User::factory()->create(['email' => 'rina@example.test', 'password' => 'right-password']);

        $this->assertTrue($this->app->make(LocalCredentialChecker::class)->isValid('rina@example.test', 'right-password'));
    }

    public function test_it_refuses_a_wrong_password_an_unknown_email_and_a_user_without_a_password(): void
    {
        User::factory()->create(['email' => 'rina@example.test', 'password' => 'right-password']);
        User::factory()->create(['email' => 'nopass@example.test', 'password' => null]);
        $checker = $this->app->make(LocalCredentialChecker::class);

        $this->assertFalse($checker->isValid('rina@example.test', 'wrong-password'));
        $this->assertFalse($checker->isValid('unknown@example.test', 'right-password'));
        $this->assertFalse($checker->isValid('nopass@example.test', ''));
    }

    /**
     * A row from before email sign-in may have no email. It can't sign in, and nothing gives it one (issue 44).
     */
    public function test_a_user_without_an_email_cannot_sign_in_and_is_not_given_one(): void
    {
        $user = User::factory()->create(['employee_id' => 'EMP-OLD', 'email' => null, 'password' => 'right-password']);

        $this->assertFalse($this->app->make(LocalCredentialChecker::class)->isValid('', 'right-password'));
        $this->postJson('/api/auth/login', ['email' => 'emp-old@example.test', 'password' => 'right-password'])
            ->assertUnauthorized();

        $this->assertNull($user->refresh()->email);
    }

    public function test_the_login_endpoint_uses_it(): void
    {
        User::factory()->create(['email' => 'rina@example.test', 'password' => 'right-password']);

        $this->postJson('/api/auth/login', ['email' => 'rina@example.test', 'password' => 'wrong-password'])->assertUnauthorized();
        $this->postJson('/api/auth/login', ['email' => 'rina@example.test', 'password' => 'right-password'])->assertOk();
    }
}
