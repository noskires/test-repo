<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * One immutable version of a PM Checklist. PM Tasks keep the version they were generated with.
 */
#[Fillable(['version_number', 'content_fingerprint'])]
class ChecklistTemplateVersion extends Model
{
    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Checklist versions are immutable. Load a changed definition to create a new version.');
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
            'checklist_template_id' => 'integer',
            'version_number' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<ChecklistTemplate, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(ChecklistTemplate::class, 'checklist_template_id');
    }

    /**
     * @return HasMany<ChecklistItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(ChecklistItem::class)->orderBy('position');
    }
}
