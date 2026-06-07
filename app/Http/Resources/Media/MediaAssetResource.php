<?php

namespace App\Http\Resources\Media;

use App\Domain\Media\Support\MediaAssetContentUrl;
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
            'import_job_id' => $this->import_job_id,
            'source_kind' => $this->source_kind,
            'source_media_ref' => $this->source_media_ref,
            'source_filename' => $this->source_filename,
            'url' => $this->public_url,
            'content_url' => MediaAssetContentUrl::path($this->resource),
            'mime_type' => $this->mime_type,
            'size_bytes' => $this->size_bytes,
            // Clients can use this to verify downloaded media bytes.
            'checksum_sha256' => $this->checksum_sha256,
            'original_filename' => $this->original_filename,
            'created_at' => $this->created_at?->toJSON(),
            'updated_at' => $this->updated_at?->toJSON(),
        ];
    }
}
