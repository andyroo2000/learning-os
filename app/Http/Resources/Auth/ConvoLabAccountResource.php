<?php

namespace App\Http\Resources\Auth;

use App\Support\DateTime\ConvoLabTimestamp;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ConvoLabAccountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->convolab_id,
            'email' => $this->email,
            'name' => $this->name,
            'displayName' => $this->display_name,
            'avatarColor' => $this->avatar_color,
            'role' => $this->role,
            'preferredStudyLanguage' => $this->preferred_study_language,
            'preferredNativeLanguage' => $this->preferred_native_language,
            'proficiencyLevel' => $this->proficiency_level,
            'onboardingCompleted' => $this->onboarding_completed,
            'emailVerified' => $this->email_verified,
            'emailVerifiedAt' => ConvoLabTimestamp::serialize($this->email_verified_at),
            'createdAt' => ConvoLabTimestamp::serialize($this->created_at),
            'updatedAt' => ConvoLabTimestamp::serialize($this->updated_at),
        ];
    }
}
