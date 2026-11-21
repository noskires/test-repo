<?php

namespace App\MasterData\Data;

/**
 * A Site as read from the external master data system.
 */
final readonly class Site
{
    public function __construct(
        public string $id,
        public string $name,
    ) {}
}
