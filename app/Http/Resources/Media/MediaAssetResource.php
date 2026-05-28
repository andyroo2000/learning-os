<?php

namespace App\Http\Resources\Media;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MediaAssetResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'url' => $this->when($this->public_url !== null, fn () => $this->public_url),
            'mime_type' => $this->mime_type,
            'size_bytes' => $this->size_bytes,
            'checksum_sha256' => $this->checksum_sha256,
            'original_filename' => $this->original_filename,
            'created_at' => $this->created_at?->toJSON(),
            'updated_at' => $this->updated_at?->toJSON(),
        ];
    }
}
