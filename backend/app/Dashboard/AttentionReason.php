<?php

namespace App\Dashboard;

/**
 * Why an Equipment requires attention (issue 28). The case order is the order the reasons are listed in.
 */
enum AttentionReason: string
{
    case OverduePm = 'overdue_pm';
    case FailedItems = 'failed_items';
    case EmergencyECall = 'emergency_e_call';
    case NotOperating = 'not_operating';
}
