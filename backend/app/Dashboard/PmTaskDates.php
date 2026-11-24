<?php

namespace App\Dashboard;

use App\Enums\PmStatus;
use DateTimeInterface;

/**
 * The parts of a PM Task the dashboard KPIs need: its PM Status, due date and Work Done Date. Only the calendar
 * dates count.
 */
final readonly class PmTaskDates
{
    public function __construct(
        public PmStatus $status,
        public DateTimeInterface $dueDate,
        public ?DateTimeInterface $workDoneDate,
    ) {}
}
