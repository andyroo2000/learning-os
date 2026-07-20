<?php

namespace App\Http\Resources\Content;

use App\Support\DateTime\ConvoLabTimestamp;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ContentAudioScriptResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'episodeId' => $this->episode_id,
            'status' => $this->status,
            'imageStatus' => $this->image_status,
            'imageErrorMessage' => $this->image_error_message,
            'voiceId' => $this->voice_id,
            'voiceProvider' => $this->voice_provider,
            'generationMetadataJson' => $this->generation_metadata,
            'errorMessage' => $this->error_message,
            'createdAt' => ConvoLabTimestamp::serialize($this->created_at),
            'updatedAt' => ConvoLabTimestamp::serialize($this->updated_at),
            'segments' => ContentAudioScriptSegmentResource::collection($this->whenLoaded('segments')),
            'renders' => ContentAudioScriptRenderResource::collection($this->whenLoaded('renders')),
        ];
    }
}
