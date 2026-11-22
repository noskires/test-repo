<?php

namespace App\Enums;

use Carbon\CarbonImmutable;

/**
 * How often every Equipment at a Site gets Preventive Maintenance. One per Site, from a fixed MVP list.
 */
enum PmFrequency: string
{
    case Monthly = 'monthly';
    case TwiceAMonth = 'twice_a_month';
    case BiMonthly = 'bi_monthly';
    case Quarterly = 'quarterly';
    case SemiAnnual = 'semi_annual';
    case Annual = 'annual';

    /**
     * The next due date on the sliding schedule (PRD v2 §6.1). Twice a month adds exactly 15 days.
     * Month-based intervals clamp to the month's last day (31 Jan + 1 month = 28/29 Feb).
     */
    public function nextDueDate(CarbonImmutable $from): CarbonImmutable
    {
        return match ($this) {
            self::TwiceAMonth => $from->addDays(15),
            self::Monthly => $from->addMonthsNoOverflow(1),
            self::BiMonthly => $from->addMonthsNoOverflow(2),
            self::Quarterly => $from->addMonthsNoOverflow(3),
            self::SemiAnnual => $from->addMonthsNoOverflow(6),
            self::Annual => $from->addMonthsNoOverflow(12),
        };
    }
}
