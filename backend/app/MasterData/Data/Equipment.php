<?php

namespace App\MasterData\Data;

use DateTimeImmutable;

/**
 * An Equipment as read from the master data. It always belongs to exactly one Site. An inactive Equipment has left
 * the source database (issue 03): it gets no new PM Tasks or E-Calls, but its existing records still read.
 */
final readonly class Equipment
{
    public function __construct(
        public string $id,
        public string $siteId,
        public string $name,
        public string $categoryId,
        public ?string $location = null,
        public ?DateTimeImmutable $installedOn = null,
        public ?string $operatingStatus = null,
        public bool $isActive = true,
    ) {}
}
