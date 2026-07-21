<?php

namespace App\Http\Resources\Admin;

use App\Support\DateTime\ConvoLabTimestamp;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminUserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->convolab_id,
            'email' => $this->email,
            'name' => $this->name,
            'displayName' => $this->display_name,
            'avatarColor' => $this->avatar_color,
            'avatarUrl' => $this->avatar_url,
            'role' => $this->role,
            'createdAt' => ConvoLabTimestamp::serialize($this->created_at),
            'updatedAt' => ConvoLabTimestamp::serialize($this->updated_at),
            '_count' => [
                'episodes' => (int) $this->user->episodes_count,
                'courses' => (int) $this->user->courses_count,
            ],
        ];
    }
}
