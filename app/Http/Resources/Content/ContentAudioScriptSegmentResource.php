<?php

namespace App\Http\Resources\Content;

use App\Support\DateTime\ConvoLabTimestamp;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ContentAudioScriptSegmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'scriptId' => $this->script_id,
            'order' => $this->sort_order,
            'text' => $this->text,
            'reading' => $this->reading,
            'translation' => $this->translation,
            'imagePrompt' => $this->image_prompt,
            'imageStatus' => $this->image_status,
            'imageErrorMessage' => $this->image_error_message,
            'imageMediaId' => $this->image_media_id,
            'imageGeneratedAt' => ConvoLabTimestamp::serialize($this->image_generated_at),
            'metadata' => $this->metadata,
            'createdAt' => ConvoLabTimestamp::serialize($this->created_at),
            'updatedAt' => ConvoLabTimestamp::serialize($this->updated_at),
            'imageMedia' => $this->whenLoaded('imageMedia', fn () => $this->imageMedia === null ? null : [
                'id' => $this->imageMedia->id,
                'mediaKind' => $this->imageMedia->media_kind,
                'contentType' => $this->imageMedia->content_type,
                'publicUrl' => $this->imageMedia->public_url,
                'sourceFilename' => $this->imageMedia->source_filename,
            ]),
        ];
    }
}
