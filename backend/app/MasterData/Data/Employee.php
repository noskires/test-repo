<?php

namespace App\MasterData\Data;

/**
 * An employee as read from the master data (imported from the source database, issue 03). The Immediate Head is
 * null at the top of the chain. An inactive employee has left the source; their PSS user is not touched (issue 47).
 */
final readonly class Employee
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $immediateHeadId = null,
        public ?string $positionTitle = null,
        public bool $isActive = true,
    ) {}
}
