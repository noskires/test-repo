<?php

namespace App\MasterData\Data;

/**
 * A Site as read from the master data. An inactive Site has left the source database (issue 03).
 */
final readonly class Site
{
    public function __construct(
        public string $id,
        public string $name,
        public bool $isActive = true,
    ) {}
}
