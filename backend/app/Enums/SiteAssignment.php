<?php

namespace App\Enums;

/**
 * Which per-Site assignment a history entry is about.
 */
enum SiteAssignment: string
{
    case Vendor = 'vendor';
    case Eic = 'eic';
}
