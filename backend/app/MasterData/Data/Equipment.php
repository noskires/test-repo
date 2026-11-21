<?php

namespace App\MasterData\Data;

use DateTimeImmutable;

/**
 * An Equipment as read from the external master data system. It always belongs to exactly one Site.
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
    ) {}
}
