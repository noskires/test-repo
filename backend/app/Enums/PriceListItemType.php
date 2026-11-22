<?php

namespace App\Enums;

/**
 * Whether a Price List item is a material/consumable or a scope-of-work (service) item.
 */
enum PriceListItemType: string
{
    case Material = 'material';
    case Service = 'service';
}
