<?php

namespace App\Auth;

/**
 * Port that says whether an email and password are valid (ADR 0004, amended 2026-11-24 for issue 44), the same pattern
 * as MasterDataDirectory. Login uses only this port, so a different checker can be plugged in later.
 */
interface CredentialChecker
{
    /**
     * @param  string  $email  already trimmed and lower-cased (User::normalizeEmail)
     */
    public function isValid(string $email, string $password): bool;
}
