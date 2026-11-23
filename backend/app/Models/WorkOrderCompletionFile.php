<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A file on a completion declaration: kind "document" (completion documents, at least one per declaration) or
 * "photo". Stored on the configured private disk.
 */
#[Fillable(['kind', 'disk', 'path', 'original_name', 'mime_type', 'size_bytes'])]
class WorkOrderCompletionFile extends Model
{
    public const string KIND_DOCUMENT = 'document';

    public const string KIND_PHOTO = 'photo';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'work_order_completion_id' => 'integer',
            'size_bytes' => 'integer',
        ];
    }
}
