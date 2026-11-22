<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A material or consumable used on a PM Task: free text with a quantity in thousandths. No price.
 */
#[Fillable(['description', 'quantity_thousandths'])]
class PmReportMaterial extends Model
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
            'quantity_thousandths' => 'integer',
        ];
    }
}
