<?php

namespace App\Waiting;

use App\Models\User;

/**
 * One source of items waiting for a user's action ("Waiting for me", issue 24). Each workflow adds its own
 * provider and tags it with TAG in AppServiceProvider; the endpoint merges and sorts every tagged provider's items.
 */
interface WaitingItemProvider
{
    public const TAG = 'waiting-item-providers';

    /**
     * The items waiting for this user. Return nothing (not an error) when the user has no role here.
     *
     * @return iterable<WaitingItem>
     */
    public function itemsFor(User $user): iterable;
}
