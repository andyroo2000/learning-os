<?php

namespace App\Domain\Calendar\Actions;

use App\Domain\Calendar\Models\GoogleCalendarConnectIntent;

final class PruneExpiredGoogleCalendarConnectIntentsAction
{
    public function handle(): int
    {
        return GoogleCalendarConnectIntent::query()->where('expires_at', '<=', now())->delete();
    }
}
