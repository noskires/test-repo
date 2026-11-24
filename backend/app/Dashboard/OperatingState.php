<?php

namespace App\Dashboard;

/**
 * Whether an Equipment is operating, according to the external master data's operating status (issue 28).
 *
 * Provisional (product-owner, 2026-11-24; the external values are still open, issue 03 Q4): only the values in
 * OPERATIONAL_VALUES count as operating, null or blank is unknown, and anything else is non-operational.
 */
enum OperatingState: string
{
    case Operational = 'operational';
    case NonOperational = 'non_operational';
    case Unknown = 'unknown';

    /** Lower-case, trimmed directory values that mean "operating". */
    public const OPERATIONAL_VALUES = ['operational'];

    public static function classify(?string $operatingStatus): self
    {
        $value = strtolower(trim((string) $operatingStatus));

        return match (true) {
            $value === '' => self::Unknown,
            in_array($value, self::OPERATIONAL_VALUES, true) => self::Operational,
            default => self::NonOperational,
        };
    }
}
