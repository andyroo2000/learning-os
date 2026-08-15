<?php

namespace App\Domain\Calendar\Actions;

use App\Domain\Calendar\Models\GoogleCalendarConnection;

final class DisconnectGoogleCalendarAction
{
    public function handle(int $userId): void
    {
        GoogleCalendarConnection::query()
            ->where('user_id', $userId)
            ->delete();
    }
}
