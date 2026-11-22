<?php

namespace App\Events;

use App\Enums\PmFrequency;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A Site's PM Frequency really changed. The old value is null the first time it is set.
 * Issue 08 listens to this to re-date the Site's Scheduled PM Tasks.
 */
class SitePmFrequencyChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly string $siteId,
        public readonly ?PmFrequency $oldFrequency,
        public readonly PmFrequency $newFrequency,
    ) {}
}
