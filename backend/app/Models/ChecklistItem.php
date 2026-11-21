<?php

namespace App\Models;

use App\Enums\ChecklistResponseType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One immutable check within a checklist version. Its ID is stable, so PM Reports can point at it.
 */
#[Fillable([
    'position',
    'label',
    'response_type',
    'mandatory',
    'remarks_required_on_fail',
    'photo_required',
    'acceptable_min',
    'acceptable_max',
    'acceptable_criteria',
])]
class ChecklistItem extends Model
{
    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Checklist items are immutable. Load a changed definition to create a new version.');
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
            'position' => 'integer',
            'response_type' => ChecklistResponseType::class,
            'mandatory' => 'boolean',
            'remarks_required_on_fail' => 'boolean',
            'photo_required' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<ChecklistTemplateVersion, $this>
     */
    public function version(): BelongsTo
    {
        return $this->belongsTo(ChecklistTemplateVersion::class, 'checklist_template_version_id');
    }
}
