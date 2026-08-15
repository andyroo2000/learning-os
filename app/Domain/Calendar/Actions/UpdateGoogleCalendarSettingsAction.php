<?php

namespace App\Domain\Calendar\Actions;

use App\Domain\Calendar\Data\GoogleCalendarSettings;
use App\Domain\Calendar\Models\GoogleCalendarConnection;
use Illuminate\Support\Facades\DB;

final class UpdateGoogleCalendarSettingsAction
{
    public function handle(int $userId, GoogleCalendarSettings $settings): GoogleCalendarSettings
    {
        return DB::transaction(function () use ($userId, $settings): GoogleCalendarSettings {
            $connection = GoogleCalendarConnection::query()->where('user_id', $userId)->lockForUpdate()->firstOrFail();
            if (GoogleCalendarSettings::fromStored($connection->settings)?->toArray() !== $settings->toArray()) {
                $connection->forceFill(['settings' => $settings->toArray(), 'sync_cursors' => null, 'last_synced_at' => null])->save();
            }

            return $settings;
        });
    }
}
