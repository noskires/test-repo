<?php

namespace App\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Hash;

/**
 * Checks the password against the hashed users.password of the user with that email, in every environment (issue 44).
 * A user without an email or a password never matches. An unknown email still costs one hash check, so the response
 * time doesn't tell who is registered (Q16).
 */
class LocalCredentialChecker implements CredentialChecker
{
    private static ?string $dummyHash = null;

    public function isValid(string $email, string $password): bool
    {
        $hashedPassword = $email === '' ? null : User::query()->where('email', $email)->value('password');

        if (! is_string($hashedPassword)) {
            Hash::check($password, self::$dummyHash ??= Hash::make('pss-dummy-password'));

            return false;
        }

        return Hash::check($password, $hashedPassword);
    }
}
