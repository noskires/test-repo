<?php

namespace App\MasterData;

use App\MasterData\Data\Employee;
use InvalidArgumentException;

/**
 * Walks an employee's Immediate Head chain upward (Requestor -> Team Head -> Division Head -> Center Head -> Sector Head).
 * Used by Work Order endorsements and billing endorsements (issues 19 and 23).
 */
final class ImmediateHeadChain
{
    public function __construct(private readonly MasterDataDirectory $directory) {}

    /**
     * The employee $level steps above the given employee, or null when the chain runs out
     * or an employee along the way is missing from the directory.
     */
    public function headAtLevel(string $employeeId, int $level): ?Employee
    {
        if ($level < 1) {
            throw new InvalidArgumentException("Immediate Head level must be at least 1, got {$level}.");
        }

        $current = $this->directory->findEmployee($employeeId);

        for ($step = 0; $step < $level; $step++) {
            if ($current?->immediateHeadId === null) {
                return null;
            }

            $current = $this->directory->findEmployee($current->immediateHeadId);
        }

        return $current;
    }
}
