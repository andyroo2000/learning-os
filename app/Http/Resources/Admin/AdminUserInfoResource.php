<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminUserInfoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->convolab_id,
            'email' => $this->email,
            'name' => $this->name,
            'displayName' => $this->display_name,
            'role' => $this->role,
            'avatarColor' => $this->avatar_color,
            'avatarUrl' => $this->avatar_url,
            'preferredStudyLanguage' => $this->preferred_study_language,
            'preferredNativeLanguage' => $this->preferred_native_language,
            'onboardingCompleted' => $this->onboarding_completed,
        ];
    }
}
