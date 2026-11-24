<?php

namespace Tests\Feature;

use App\Models\User;
use Tests\TestCase;

/**
 * The enforced morph map knows users as `user` (issue 04, alignment item 3), ready for database notifications.
 */
class UserMorphAliasTest extends TestCase
{
    public function test_the_user_morph_alias_is_user(): void
    {
        $this->assertSame('user', (new User)->getMorphClass());
    }
}
