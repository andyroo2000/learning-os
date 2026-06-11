<?php

namespace App\Http\Resources\Study;

use App\Domain\Study\Enums\StudyImportStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StudyImportJobResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->statusValue(),
            'source_type' => $this->source_type,
            'source_filename' => $this->source_filename,
            'source_content_type' => $this->source_content_type,
            'source_size_bytes' => $this->source_size_bytes,
            'deck_name' => $this->deck_name,
            'preview' => $this->preview_json,
            'summary' => $this->summary_json,
            'error_message' => $this->error_message,
            'started_at' => $this->started_at?->toJSON(),
            'uploaded_at' => $this->uploaded_at?->toJSON(),
            'upload_completed_at' => $this->upload_completed_at?->toJSON(),
            'upload_expires_at' => $this->upload_expires_at?->toJSON(),
            'completed_at' => $this->completed_at?->toJSON(),
            'created_at' => $this->created_at?->toJSON(),
            'updated_at' => $this->updated_at?->toJSON(),
        ];
    }

    private function statusValue(): ?string
    {
        $status = $this->resource->getAttributes()['status'] ?? null;

        if ($status instanceof StudyImportStatus) {
            return $status->value;
        }

        return $status === null ? null : (string) $status;
    }
}
