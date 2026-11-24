<?php

namespace App\Http\Resources\Admin;

use App\Support\DateTime\ConvoLabTimestamp;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class BetaRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'devices' => $this->devices,
            'message' => $this->message,
            'createdAt' => ConvoLabTimestamp::serialize($this->created_at),
            'approvedAt' => ConvoLabTimestamp::serialize($this->approved_at),
            'invitationSentAt' => ConvoLabTimestamp::serialize($this->invitation_sent_at),
            'inviteCode' => $this->inviteCode?->code,
            'redeemedAt' => ConvoLabTimestamp::serialize($this->inviteCode?->used_at),
        ];
    }
}
