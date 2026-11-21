<?php

namespace App\Models;

use App\Enums\SiteAssignment;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * History entry for one change to a Site's Vendor or EIC: who, when (created_at), and the old and new value.
 * Vendor changes fill the *_vendor_id columns; EIC changes fill the *_eic_employee_id columns.
 */
#[Fillable([
    'site_id',
    'assignment',
    'old_vendor_id',
    'new_vendor_id',
    'old_eic_employee_id',
    'new_eic_employee_id',
    'changed_by_user_id',
])]
class SiteAssignmentChange extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'assignment' => SiteAssignment::class,
            'old_vendor_id' => 'integer',
            'new_vendor_id' => 'integer',
            'changed_by_user_id' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_user_id');
    }
}
