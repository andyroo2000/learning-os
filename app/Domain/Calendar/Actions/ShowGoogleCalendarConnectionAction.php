<?php

namespace App\Domain\Calendar\Actions;

use App\Domain\Calendar\Data\GoogleCalendarSettings;
use App\Domain\Calendar\Models\GoogleCalendarConnection;

final class ShowGoogleCalendarConnectionAction
{
    public function __construct(private readonly GetNextGoogleCalendarLessonAction $nextLesson) {}

    /** @return array{connected: bool, accountEmail: ?string, scopes: list<string>, settings: ?array, connectedAt: ?string, lastSyncedAt: ?string, sync: ?array{status:string,errorCode:?string,statusAt:?string}, nextLesson: ?array{title:string,startsAt:string,endsAt:string}} */
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
                'sync' => null,
                'nextLesson' => null,
            ];
        }

        return $this->connection($connection);
    }

    /** @return array{connected: true, accountEmail: ?string, scopes: list<string>, settings: ?array, connectedAt: ?string, lastSyncedAt: ?string, sync: array{status:string,errorCode:?string,statusAt:?string}, nextLesson: ?array{title:string,startsAt:string,endsAt:string}} */
    public function connection(GoogleCalendarConnection $connection): array
    {
        $settings = GoogleCalendarSettings::fromStored($connection->settings);

        return [
            'connected' => true,
            'accountEmail' => $connection->account_email,
            'scopes' => $connection->scopes ?? [],
            'settings' => $settings?->toArray(),
            'connectedAt' => $connection->connected_at?->utc()->toIso8601ZuluString(),
            'lastSyncedAt' => $connection->last_synced_at?->utc()->toIso8601ZuluString(),
            'sync' => [
                'status' => $connection->sync_status->value,
                'errorCode' => $connection->sync_error_code?->value,
                'statusAt' => $connection->sync_status_at?->utc()->toIso8601ZuluString(),
            ],
            'nextLesson' => $settings === null ? null : $this->nextLesson->handle($connection, $settings),
        ];
    }
}
