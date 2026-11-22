<?php

namespace App\Models;

use App\Enums\ApprovalAction;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use LogicException;

/**
 * The permanent log entry for one Approve, Return, Reject or Cancel on a workflow subject: who acted (user and
 * employee ID at the time), the action, remarks, and the previous and resulting status. Append-only: there is
 * no updated_at, and updating or deleting a record throws. Write records only through ApprovalRecorder.
 */
#[Fillable(['action', 'actor_user_id', 'actor_employee_id', 'remarks', 'previous_status', 'resulting_status'])]
class ApprovalRecord extends Model
{
    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Approval Records are append-only and cannot be changed.');
        });

        static::deleting(function (): never {
            throw new LogicException('Approval Records are append-only and cannot be deleted.');
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'subject_id' => 'integer',
            'action' => ApprovalAction::class,
            'actor_user_id' => 'integer',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    /**
     * Oldest first, ties broken by ID.
     *
     * @param  Builder<ApprovalRecord>  $query
     */
    public function scopeChronological(Builder $query): void
    {
        $query->orderBy('created_at')->orderBy('id');
    }
}
