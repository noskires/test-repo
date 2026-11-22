<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A file on a PM Report revision. Photos of a Checklist Item carry its checklist_item_id; general attachments
 * (the PM Service Report, other photos) leave it null.
 */
#[Fillable(['checklist_item_id', 'disk', 'path', 'original_name', 'mime_type', 'size_bytes'])]
class PmReportAttachment extends Model
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
            'size_bytes' => 'integer',
        ];
    }
}
