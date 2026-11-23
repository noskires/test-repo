<?php

namespace App\Waiting;

use App\Models\User;
use Illuminate\Contracts\Container\Container;

/**
 * Merges every tagged WaitingItemProvider's items for a user and sorts them oldest waiting first.
 */
class WaitingList
{
    public function __construct(private Container $container) {}

    /**
     * @return list<WaitingItem>
     */
    public function for(User $user): array
    {
        $items = [];

        /** @var WaitingItemProvider $provider */
        foreach ($this->container->tagged(WaitingItemProvider::TAG) as $provider) {
            foreach ($provider->itemsFor($user) as $item) {
                $items[] = $item;
            }
        }

        usort($items, WaitingItem::compare(...));

        return $items;
    }
}
