<?php

namespace App\Enums;

/**
 * Which workflow step a PM Task is on (GLOSSARY "PM Status"). Generation only creates Scheduled tasks.
 */
enum PmStatus: string
{
    case Scheduled = 'scheduled';
    case InProgress = 'in_progress';
    case ForValidation = 'for_validation';
    case Returned = 'returned';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    /**
     * Open tasks are every status except Completed and Cancelled. An Equipment has at most one open task.
     */
    public function isOpen(): bool
    {
        return $this !== self::Completed && $this !== self::Cancelled;
    }
}
