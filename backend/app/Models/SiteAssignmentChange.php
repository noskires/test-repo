<?php

namespace App\Models;

use App\Enums\PmFrequency;
use App\Enums\SiteAssignment;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * History entry for one change to a Site's Vendor, EIC or PM Frequency: who, when (created_at), and the old and new value.
 * Vendor changes fill the *_vendor_id columns, EIC changes the *_eic_employee_id columns, and PM Frequency
 * changes the *_pm_frequency columns.
 */
#[Fillable([
    'site_id',
    'assignment',
    'old_vendor_id',
    'new_vendor_id',
    'old_eic_employee_id',
    'new_eic_employee_id',
    'old_pm_frequency',
    'new_pm_frequency',
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
            'old_pm_frequency' => PmFrequency::class,
            'new_pm_frequency' => PmFrequency::class,
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
