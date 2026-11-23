<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The actual quantity the Site Vendor used for one Work Order line, in integer thousandths. It may differ from the
 * approved quantity; the EIC validates it (issue 21).
 */
#[Fillable(['work_order_line_id', 'actual_quantity_thousandths'])]
class WorkOrderCompletionLine extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'work_order_completion_id' => 'integer',
            'work_order_line_id' => 'integer',
            'actual_quantity_thousandths' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<WorkOrderLine, $this>
     */
    public function workOrderLine(): BelongsTo
    {
        return $this->belongsTo(WorkOrderLine::class);
    }
}
