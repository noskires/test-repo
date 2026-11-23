<?php

namespace App\Waiting;

use App\Enums\ECallStatus;
use App\Models\SiteSetting;
use App\Models\User;

/**
 * E-Calls For EIC Review at every Site where the user is the current Site EIC (never vendor staff). The EIC copied
 * onto the E-Call at filing is history only. One query.
 */
class ECallsForEicReviewProvider implements WaitingItemProvider
{
    public function itemsFor(User $user): iterable
    {
        return ECallWaitingItems::from(
            ECallWaitingItems::query([ECallStatus::ForEicReview])
                ->whereIn('site_id', SiteSetting::siteIdsWhereActingEic($user)),
        );
    }
}
