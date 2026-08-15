<?php

namespace App\Domain\Calendar\Actions;

use App\Domain\Calendar\Contracts\GoogleCalendarOAuthClient;
use App\Domain\Calendar\Models\GoogleCalendarConnectIntent;

final class CreateGoogleCalendarConnectIntentAction
{
    public function __construct(private GoogleCalendarOAuthClient $google) {}

    public function handle(int $userId, string $completionTarget): string
    {
        $state = bin2hex(random_bytes(32));
        GoogleCalendarConnectIntent::query()
            ->where('user_id', $userId)
            ->where('expires_at', '<=', now())
            ->delete();

        (new GoogleCalendarConnectIntent)->forceFill([
            'state_hash' => hash('sha256', $state),
            'user_id' => $userId,
            'completion_target' => $completionTarget,
            'expires_at' => now()->addMinutes(10),
        ])->save();

        return $this->google->authorizationUrl($state);
    }
}
