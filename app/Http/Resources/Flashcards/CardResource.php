<?php

namespace App\Http\Resources\Flashcards;

use App\Http\Resources\Media\MediaAssetResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CardResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'deck_id' => $this->deck_id,
            'front_text' => $this->front_text,
            'back_text' => $this->back_text,
            // Cross-domain resource by design while cards own the response envelope.
            'media_assets' => $this->whenLoaded('mediaAssets', fn () => MediaAssetResource::collection($this->mediaAssets)),
            'created_at' => $this->created_at?->toJSON(),
            'updated_at' => $this->updated_at?->toJSON(),
        ];
    }
}
