<?php

namespace App\Enums;

/**
 * How an open PM Task stands against its due date (GLOSSARY "PM Timeliness"). Shown next to PM Status, never instead of it.
 */
enum PmTimeliness: string
{
    case Upcoming = 'upcoming';
    case Due = 'due';
    case Overdue = 'overdue';
}
