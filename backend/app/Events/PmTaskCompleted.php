<?php

namespace App\Events;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The EIC approved a PM Report: the PM Task is Completed with the vendor's Work Done Date. The successor task
 * is due one PM Frequency after that date; the daily GeneratePmTasks job creates it (issue 08).
 */
class PmTaskCompleted implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $pmTaskId,
        public readonly string $equipmentId,
        public readonly CarbonImmutable $workDoneDate,
    ) {}
}
