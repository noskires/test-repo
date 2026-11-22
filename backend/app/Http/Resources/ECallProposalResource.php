<?php

namespace App\Http\Resources;

use App\Billing\PesoAmount;
use App\Models\ECallProposal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One FSR + BOQ revision. The total is an exact peso string with five decimals (unrounded). The FSR path stays private.
 *
 * @mixin ECallProposal
 */
class ECallProposalResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'e_call_id' => $this->e_call_id,
            'revision' => $this->revision,
            'assessment_notes' => $this->assessment_notes,
            'fsr' => [
                'original_name' => $this->fsr_original_name,
                'mime_type' => $this->fsr_mime_type,
                'size_bytes' => $this->fsr_size_bytes,
            ],
            'lines' => ECallProposalLineResource::collection($this->lines),
            'total' => PesoAmount::fromMillicentavos($this->total_millicentavos),
            'submitted_by_user_id' => $this->submitted_by_user_id,
            'submitted_at' => $this->submitted_at,
        ];
    }
}
