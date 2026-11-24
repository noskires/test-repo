<?php

namespace App\Auth;

/**
 * Port that says whether an employee ID and password are valid (ADR 0004), the same pattern as MasterDataDirectory.
 *
 * Login uses only this port. LocalCredentialChecker serves local and testing; the external-system checker is issue 44.
 */
interface CredentialChecker
{
    public function isValid(string $employeeId, string $password): bool;
}
