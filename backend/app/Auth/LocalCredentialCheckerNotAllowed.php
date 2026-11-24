<?php

namespace App\Auth;

use LogicException;

/**
 * Thrown when the local credential checker is resolved outside local and testing, so production never signs in
 * against passwords stored in PSS.
 */
class LocalCredentialCheckerNotAllowed extends LogicException
{
    public static function inEnvironment(string $environment): self
    {
        return new self(
            "The local credential checker is not allowed in the [{$environment}] environment. ".
            'It only runs in local and testing; bind the external credential checker (issue 44) instead.',
        );
    }
}
