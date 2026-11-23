<?php

namespace App\Http\Resources;

use App\Billing\BoqQuantity;
use App\Models\WorkOrderCompletion;
use App\Models\WorkOrderCompletionFile;
use App\Models\WorkOrderCompletionLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One completion declaration. Quantities only (no amounts; the EIC sets the Billing Amount in issue 21). Each line
 * shows the approved and the actual quantity. Storage paths stay private.
 *
 * @mixin WorkOrderCompletion
 */
class WorkOrderCompletionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $file = fn (WorkOrderCompletionFile $file): array => [
            'id' => $file->id,
            'original_name' => $file->original_name,
            'mime_type' => $file->mime_type,
            'size_bytes' => $file->size_bytes,
        ];

        return [
            'id' => $this->id,
            'work_order_id' => $this->work_order_id,
            'revision' => $this->revision,
            'is_current' => (bool) $this->is_current,
            'notes' => $this->notes,
            'lines' => $this->lines->sortBy(fn (WorkOrderCompletionLine $line): int => $line->work_order_line_id)->values()->map(fn (WorkOrderCompletionLine $line): array => [
                'work_order_line_id' => $line->work_order_line_id,
                'description' => $line->workOrderLine->description,
                'unit_of_measure' => $line->workOrderLine->unit_of_measure,
                'approved_quantity' => BoqQuantity::fromThousandths($line->workOrderLine->quantity_thousandths),
                'actual_quantity' => BoqQuantity::fromThousandths($line->actual_quantity_thousandths),
            ])->all(),
            'documents' => $this->files->where('kind', WorkOrderCompletionFile::KIND_DOCUMENT)->values()->map($file)->all(),
            'photos' => $this->files->where('kind', WorkOrderCompletionFile::KIND_PHOTO)->values()->map($file)->all(),
            'submitted_by_user_id' => $this->submitted_by_user_id,
            'submitted_at' => $this->submitted_at,
        ];
    }
}
