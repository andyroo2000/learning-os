<?php

namespace App\Http\Resources\Auth;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Laravel\Sanctum\PersonalAccessToken;

class AccessTokenResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $currentAccessToken = $request->user()?->currentAccessToken();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'abilities' => $this->abilities,
            'last_used_at' => $this->last_used_at?->toJSON(),
            'expires_at' => $this->expires_at?->toJSON(),
            'created_at' => $this->created_at?->toJSON(),
            'is_current' => $currentAccessToken instanceof PersonalAccessToken
                && $currentAccessToken->getKey() === $this->getKey(),
        ];
    }
}
