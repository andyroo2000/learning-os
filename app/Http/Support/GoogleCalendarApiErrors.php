<?php

namespace App\Http\Support;

use App\Domain\Calendar\Exceptions\GoogleCalendarManualSyncException;
use App\Domain\Calendar\Exceptions\GoogleCalendarProviderException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;

final class GoogleCalendarApiErrors
{
    public static function manualSync(GoogleCalendarManualSyncException $exception): JsonResponse
    {
        [$status, $code, $message] = match ($exception->reason()) {
            GoogleCalendarManualSyncException::SETTINGS_REQUIRED => [422, GoogleCalendarManualSyncException::SETTINGS_REQUIRED, 'Choose Google Calendar sync settings before syncing.'],
            default => [503, GoogleCalendarManualSyncException::SYNC_UNAVAILABLE, 'Google Calendar sync is temporarily unavailable.'],
        };

        return response()->json(['error' => ['code' => $code, 'message' => $message]], $status);
    }

    public static function response(ModelNotFoundException|GoogleCalendarProviderException $exception): JsonResponse
    {
        $reason = $exception instanceof ModelNotFoundException ? 'not_connected' : $exception->reason();
        [$status, $message] = match ($reason) {
            'not_connected' => [409, 'Connect Google Calendar before continuing.'],
            GoogleCalendarProviderException::RECONNECT_REQUIRED => [409, 'Reconnect Google Calendar before continuing.'],
            GoogleCalendarProviderException::SYNC_TOKEN_EXPIRED => [409, 'Google Calendar changed; refresh its calendars and try again.'],
            GoogleCalendarProviderException::INVALID_RESPONSE => [502, 'Google Calendar returned an invalid response.'],
            GoogleCalendarProviderException::INVALID_REQUEST => [500, 'Google Calendar could not be queried safely.'],
            default => [503, 'Google Calendar is temporarily unavailable.'],
        };

        return response()->json(['error' => ['code' => $reason, 'message' => $message]], $status);
    }
}
