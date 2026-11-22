<?php

namespace App\Policies;

use App\Models\ECall;
use App\Models\User;

/**
 * Vendor staff only see E-Calls routed to their Vendor at filing time, and only they submit its FSR and BOQ.
 * Other users are not scoped yet (issue 04).
 */
class ECallPolicy
{
    /**
     * Determine whether the user can view the E-Call.
     */
    public function view(User $user, ECall $eCall): bool
    {
        return $user->vendor_id === null || $user->vendor_id === $eCall->vendor_id;
    }

    /**
     * Only staff of the E-Call's Site Vendor (captured at filing time) may submit its FSR and BOQ.
     */
    public function submitProposal(User $user, ECall $eCall): bool
    {
        return $user->vendor_id !== null && $user->vendor_id === $eCall->vendor_id;
    }
}
