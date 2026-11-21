<?php

namespace App\MasterData\Data;

/**
 * An Equipment Category as read from the external master data system.
 */
final readonly class EquipmentCategory
{
    public function __construct(
        public string $id,
        public string $name,
    ) {}
}
