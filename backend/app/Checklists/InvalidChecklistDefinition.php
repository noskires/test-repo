<?php

namespace App\Checklists;

use InvalidArgumentException;

/**
 * A PM Checklist definition that cannot be loaded. Nothing is written when this is thrown.
 */
class InvalidChecklistDefinition extends InvalidArgumentException
{
    public static function because(string $checklistKey, string $reason): self
    {
        return new self("Checklist definition [{$checklistKey}] is invalid: {$reason}");
    }
}
