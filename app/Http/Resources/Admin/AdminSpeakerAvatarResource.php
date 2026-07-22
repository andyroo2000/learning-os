<?php

namespace App\Http\Resources\Admin;

use App\Support\DateTime\ConvoLabTimestamp;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminSpeakerAvatarResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'filename' => $this->filename,
            'croppedUrl' => $this->cropped_url,
            'originalUrl' => $this->original_url,
            'language' => $this->language,
            'gender' => $this->gender,
            'tone' => $this->tone,
            'createdAt' => ConvoLabTimestamp::serialize($this->created_at),
            'updatedAt' => ConvoLabTimestamp::serialize($this->updated_at),
        ];
    }
}
