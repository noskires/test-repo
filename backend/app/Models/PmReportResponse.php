<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One response to one Checklist Item in a PM Report revision. Photo items have no value; their photos are attachments.
 */
#[Fillable(['checklist_item_id', 'value', 'remarks', 'is_failed'])]
class PmReportResponse extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'pm_report_id' => 'integer',
            'checklist_item_id' => 'integer',
            'is_failed' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<ChecklistItem, $this>
     */
    public function checklistItem(): BelongsTo
    {
        return $this->belongsTo(ChecklistItem::class);
    }
}
