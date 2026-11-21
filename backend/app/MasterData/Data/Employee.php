<?php

namespace App\MasterData\Data;

/**
 * An employee as read from the external master data system. The Immediate Head is null at the top of the chain.
 */
final readonly class Employee
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $immediateHeadId = null,
    ) {}
}
