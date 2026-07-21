<?php

namespace App\Http\Resources\Admin;

use App\Support\DateTime\ConvoLabTimestamp;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminInviteCodeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'usedBy' => $this->convolab_used_by,
            'usedAt' => ConvoLabTimestamp::serialize($this->used_at),
            'createdAt' => ConvoLabTimestamp::serialize($this->created_at),
            'user' => $this->whenLoaded('user', fn (): ?array => $this->user === null ? null : [
                'id' => $this->user->convolab_id,
                'email' => $this->user->email,
                'name' => $this->user->name,
            ]),
        ];
    }
}
