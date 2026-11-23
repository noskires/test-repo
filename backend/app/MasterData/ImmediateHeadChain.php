<?php

namespace App\MasterData;

use App\MasterData\Data\Employee;
use InvalidArgumentException;

/**
 * Walks an employee's Immediate Head chain upward (Requestor -> Team Head -> Division Head -> Center Head -> Sector Head).
 * Used by Work Order endorsements and billing endorsements (issues 19 and 23).
 *
 * A chain that loops back on itself (an employee ID repeating while walking up, e.g. A -> B -> A or A -> A) is a
 * broken chain: headAtLevel() returns null from the repeating level on, and repeatedEmployeeAt() names the repeat.
 *
 * Each instance looks every employee up in the directory at most once (issue 40), so one instance shared across a
 * list resolves each Requestor's chain once. Resolve a fresh instance per action or request: never keep one longer,
 * or it would miss org changes.
 */
final class ImmediateHeadChain
{
    /**
     * @var array<string, ?Employee>
     */
    private array $employees = [];

    public function __construct(private readonly MasterDataDirectory $directory) {}

    /**
     * The employee $level steps above the given employee, or null when the chain runs out, an employee along the
     * way is missing from the directory, or the chain loops back before reaching that level.
     */
    public function headAtLevel(string $employeeId, int $level): ?Employee
    {
        ['head' => $head, 'repeated' => $repeated] = $this->walk($employeeId, $level);

        return $repeated === null ? $head : null;
    }

    /**
     * The employee ID that repeats when walking up to $level (the chain loops back), or null when there is no loop
     * within that many steps.
     */
    public function repeatedEmployeeAt(string $employeeId, int $level): ?string
    {
        return $this->walk($employeeId, $level)['repeated'];
    }

    /**
     * @return array{head: ?Employee, repeated: ?string}
     */
    private function walk(string $employeeId, int $level): array
    {
        if ($level < 1) {
            throw new InvalidArgumentException("Immediate Head level must be at least 1, got {$level}.");
        }

        $current = $this->findEmployee($employeeId);

        if ($current === null) {
            return ['head' => null, 'repeated' => null];
        }

        $seen = [$employeeId => true, $current->id => true];

        for ($step = 0; $step < $level; $step++) {
            $headId = $current->immediateHeadId;

            if ($headId === null) {
                return ['head' => null, 'repeated' => null];
            }

            if (isset($seen[$headId])) {
                return ['head' => null, 'repeated' => $headId];
            }

            $current = $this->findEmployee($headId);

            if ($current === null) {
                return ['head' => null, 'repeated' => null];
            }

            $seen[$headId] = true;
            $seen[$current->id] = true;
        }

        return ['head' => $current, 'repeated' => null];
    }

    private function findEmployee(string $employeeId): ?Employee
    {
        if (! array_key_exists($employeeId, $this->employees)) {
            $this->employees[$employeeId] = $this->directory->findEmployee($employeeId);
        }

        return $this->employees[$employeeId];
    }
}
