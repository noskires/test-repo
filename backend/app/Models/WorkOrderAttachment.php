<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A supporting file on a Work Order, stored on the configured private disk.
 */
#[Fillable(['disk', 'path', 'original_name', 'mime_type', 'size_bytes'])]
class WorkOrderAttachment extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'work_order_id' => 'integer',
            'size_bytes' => 'integer',
        ];
    }
}
