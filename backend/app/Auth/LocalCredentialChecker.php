<?php

namespace App\Auth;

use App\Models\User;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Hash;

/**
 * Checks the password against the hashed users.password (demo accounts from DemoAccountsSeeder). Local and testing only.
 */
class LocalCredentialChecker implements CredentialChecker
{
    /**
     * @throws LocalCredentialCheckerNotAllowed outside local and testing
     */
    public function __construct(Application $app)
    {
        if (! $app->environment('local', 'testing')) {
            throw LocalCredentialCheckerNotAllowed::inEnvironment($app->environment());
        }
    }

    public function isValid(string $employeeId, string $password): bool
    {
        $hashedPassword = User::query()->where('employee_id', $employeeId)->value('password');

        return is_string($hashedPassword) && Hash::check($password, $hashedPassword);
    }
}
