<?php

namespace App\Models;

use App\Enums\PssRole;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One PSS Role granted to one user (ADR 0004). Granted and revoked only through User::grantRole and User::revokeRole.
 */
#[Fillable(['user_id', 'role', 'granted_by_user_id'])]
class UserRole extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => PssRole::class,
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
