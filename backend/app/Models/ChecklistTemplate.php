<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A PM Checklist for one external Equipment Category, with numbered immutable versions.
 * At most one active template per category. Written only by ChecklistLoader in the MVP.
 */
#[Fillable(['key', 'name', 'equipment_category_id', 'is_active'])]
class ChecklistTemplate extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<ChecklistTemplateVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(ChecklistTemplateVersion::class);
    }

    /**
     * The highest-numbered version.
     *
     * @return HasOne<ChecklistTemplateVersion, $this>
     */
    public function currentVersion(): HasOne
    {
        return $this->hasOne(ChecklistTemplateVersion::class)->ofMany('version_number', 'max');
    }
}
