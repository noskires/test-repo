<?php

namespace App\Http\Resources;

use App\Models\ECallAttachment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ECallAttachment
 */
class ECallAttachmentResource extends JsonResource
{
    /**
     * Transform the resource into an array. The storage path stays private.
     *
     * @return array{id: int, original_name: string, mime_type: string, size_bytes: int}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'original_name' => $this->original_name,
            'mime_type' => $this->mime_type,
            'size_bytes' => $this->size_bytes,
        ];
    }
}
