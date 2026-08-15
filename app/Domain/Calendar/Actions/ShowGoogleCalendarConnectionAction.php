<?php

namespace App\Domain\Calendar\Actions;

use App\Domain\Calendar\Data\GoogleCalendarSettings;
use App\Domain\Calendar\Models\GoogleCalendarConnection;

final class ShowGoogleCalendarConnectionAction
{
    /** @return array{connected: bool, accountEmail: ?string, scopes: list<string>, settings: ?array, connectedAt: ?string, lastSyncedAt: ?string} */
    public function handle(int $userId): array
    {
        $connection = GoogleCalendarConnection::query()
            ->where('user_id', $userId)
            ->first();

        if ($connection === null) {
            return [
                'connected' => false,
                'accountEmail' => null,
                'scopes' => [],
                'settings' => null,
                'connectedAt' => null,
                'lastSyncedAt' => null,
            ];
        }

        return [
            'connected' => true,
            'accountEmail' => $connection->account_email,
            'scopes' => $connection->scopes ?? [],
            'settings' => GoogleCalendarSettings::fromStored($connection->settings)?->toArray(),
            'connectedAt' => $connection->connected_at?->utc()->toIso8601ZuluString(),
            'lastSyncedAt' => $connection->last_synced_at?->utc()->toIso8601ZuluString(),
        ];
    }
}
