<?php

namespace App\Domain\Calendar\Services;

use App\Domain\Calendar\Contracts\GoogleCalendarReadTransport;
use App\Domain\Calendar\Data\GoogleCalendar;
use App\Domain\Calendar\Data\GoogleCalendarEvent;
use App\Domain\Calendar\Data\GoogleCalendarEventQuery;
use App\Domain\Calendar\Data\GoogleCalendarEventTime;
use App\Domain\Calendar\Data\GoogleCalendarPage;
use App\Domain\Calendar\Data\GoogleCalendarTokenGrant;
use App\Domain\Calendar\Exceptions\GoogleCalendarProviderException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

final class LaravelGoogleCalendarReadTransport implements GoogleCalendarReadTransport
{
    private const API = 'https://www.googleapis.com/calendar/v3';

    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const MAX_TOKEN = 4096;

    public function refresh(string $refreshToken): GoogleCalendarTokenGrant
    {
        if ($refreshToken === '' || strlen($refreshToken) > self::MAX_TOKEN) {
            throw new GoogleCalendarProviderException(GoogleCalendarProviderException::RECONNECT_REQUIRED);
        }

        try {
            $response = Http::asForm()->acceptJson()->timeout(10)->post(self::TOKEN_URL, [
                'client_id' => (string) config('services.google_calendar.client_id'),
                'client_secret' => (string) config('services.google_calendar.client_secret'),
                'grant_type' => 'refresh_token',
                'refresh_token' => $refreshToken,
            ]);
        } catch (ConnectionException) {
            throw new GoogleCalendarProviderException(GoogleCalendarProviderException::UNAVAILABLE);
        }

        if (! $response->successful()) {
            $error = $response->json('error');
            throw $error === 'invalid_grant'
                ? new GoogleCalendarProviderException(GoogleCalendarProviderException::RECONNECT_REQUIRED)
                : new GoogleCalendarProviderException(GoogleCalendarProviderException::UNAVAILABLE);
        }

        $body = $this->object($response);
        $accessToken = $this->requiredString($body, 'access_token', self::MAX_TOKEN);
        $expiresIn = $body['expires_in'] ?? null;
        $rotated = $this->optionalString($body, 'refresh_token', self::MAX_TOKEN);
        if (! is_int($expiresIn) || $expiresIn < 60 || $expiresIn > 86400) {
            throw new GoogleCalendarProviderException(GoogleCalendarProviderException::INVALID_RESPONSE);
        }

        return new GoogleCalendarTokenGrant($accessToken, $expiresIn, $rotated);
    }

    public function calendars(string $accessToken, ?string $pageToken = null, int $maxResults = 100): GoogleCalendarPage
    {
        $body = $this->get($accessToken, self::API.'/users/me/calendarList', $this->query($pageToken, $maxResults, 250));
        $items = $this->items($body);

        return new GoogleCalendarPage(array_map(fn (array $item): GoogleCalendar => new GoogleCalendar(
            $this->requiredString($item, 'id', 1024),
            $this->requiredString($item, 'summary', 4096),
            match ($item['primary'] ?? false) {
                true => true, false => false, default => throw new GoogleCalendarProviderException(GoogleCalendarProviderException::INVALID_RESPONSE)
            },
            $this->requiredString($item, 'accessRole', 32),
        ), $items), $this->pageToken($body, 'nextPageToken'), $this->pageToken($body, 'nextSyncToken'));
    }

    public function events(string $accessToken, string $calendarId, GoogleCalendarEventQuery $query): GoogleCalendarPage
    {
        $incremental = $query->syncToken !== null;
        if ($calendarId === '' || strlen($calendarId) > 1024 || ($incremental && ($query->timeMin !== null || $query->timeMax !== null)) || (! $incremental && ($query->timeMin === null || ! $this->isDateTime($query->timeMin))) || ($query->timeMax !== null && ! $this->isDateTime($query->timeMax))) {
            throw new GoogleCalendarProviderException(GoogleCalendarProviderException::INVALID_REQUEST);
        }

        $parameters = $this->query($query->pageToken, $query->maxResults, 2500) + array_filter(['timeMin' => $query->timeMin, 'timeMax' => $query->timeMax, 'syncToken' => $query->syncToken, 'singleEvents' => 'true', 'showDeleted' => 'true'], fn (mixed $value): bool => $value !== null);
        if ($query->syncToken !== null && ($query->syncToken === '' || strlen($query->syncToken) > self::MAX_TOKEN)) {
            throw new GoogleCalendarProviderException(GoogleCalendarProviderException::INVALID_REQUEST);
        }
        $body = $this->get($accessToken, self::API.'/calendars/'.rawurlencode($calendarId).'/events', $parameters);
        $items = $this->items($body);

        return new GoogleCalendarPage(array_map(fn (array $item): GoogleCalendarEvent => new GoogleCalendarEvent(
            $this->requiredString($item, 'id', 1024), $this->requiredString($item, 'status', 32),
            $this->optionalString($item, 'summary', 4096), $this->eventTime($item['start'] ?? null),
            $this->eventTime($item['end'] ?? null),
            $this->eventTime($item['originalStartTime'] ?? null),
            $this->optionalDateTime($item, 'updated'),
            $this->optionalString($item, 'recurringEventId', 1024),
        ), $items), $this->pageToken($body, 'nextPageToken'), $this->pageToken($body, 'nextSyncToken'));
    }

    /** @param array<string, scalar> $query @return array<string, mixed> */
    private function get(string $token, string $url, array $query): array
    {
        if ($token === '' || strlen($token) > self::MAX_TOKEN) {
            throw new GoogleCalendarProviderException(GoogleCalendarProviderException::INVALID_REQUEST);
        }
        try {
            $response = Http::withToken($token)->acceptJson()->timeout(10)->get($url, $query);
        } catch (ConnectionException) {
            throw new GoogleCalendarProviderException(GoogleCalendarProviderException::UNAVAILABLE);
        }
        if (! $response->successful()) {
            $reason = $response->status() === 401 ? GoogleCalendarProviderException::RECONNECT_REQUIRED : ($response->status() === 410 ? GoogleCalendarProviderException::SYNC_TOKEN_EXPIRED : GoogleCalendarProviderException::UNAVAILABLE);
            throw new GoogleCalendarProviderException($reason);
        }

        return $this->object($response);
    }

    /** @return array<string, int|string> */
    private function query(?string $pageToken, int $maxResults, int $maximum): array
    {
        if ($maxResults < 1 || $maxResults > $maximum || ($pageToken !== null && ($pageToken === '' || strlen($pageToken) > self::MAX_TOKEN))) {
            throw new GoogleCalendarProviderException(GoogleCalendarProviderException::INVALID_REQUEST);
        }

        return array_filter(['maxResults' => $maxResults, 'pageToken' => $pageToken], fn (mixed $value): bool => $value !== null);
    }

    /** @return array<string, mixed> */
    private function object(Response $response): array
    {
        $body = $response->json();
        if (strlen($response->body()) > 2_000_000 || ! is_array($body) || array_is_list($body)) {
            throw new GoogleCalendarProviderException(GoogleCalendarProviderException::INVALID_RESPONSE);
        }

        return $body;
    }

    /** @param array<string, mixed> $body @return list<array<string, mixed>> */
    private function items(array $body): array
    {
        $items = $body['items'] ?? [];
        if (! is_array($items) || ! array_is_list($items) || count($items) > 2500) {
            throw new GoogleCalendarProviderException(GoogleCalendarProviderException::INVALID_RESPONSE);
        }
        foreach ($items as $item) {
            if (! is_array($item) || array_is_list($item)) {
                throw new GoogleCalendarProviderException(GoogleCalendarProviderException::INVALID_RESPONSE);
            }
        }

        return $items;
    }

    /** @param array<string, mixed> $body */
    private function requiredString(array $body, string $key, int $max): string
    {
        $value = $body[$key] ?? null;
        if (! is_string($value) || $value === '' || strlen($value) > $max) {
            throw new GoogleCalendarProviderException(GoogleCalendarProviderException::INVALID_RESPONSE);
        }

        return $value;
    }

    /** @param array<string, mixed> $body */
    private function optionalString(array $body, string $key, int $max): ?string
    {
        if (! array_key_exists($key, $body)) {
            return null;
        }
        $value = $body[$key];
        if (! is_string($value) || $value === '' || strlen($value) > $max) {
            throw new GoogleCalendarProviderException(GoogleCalendarProviderException::INVALID_RESPONSE);
        }

        return $value;
    }

    /** @param array<string, mixed> $body */
    private function pageToken(array $body, string $key): ?string
    {
        return $this->optionalString($body, $key, self::MAX_TOKEN);
    }

    private function eventTime(mixed $value): ?GoogleCalendarEventTime
    {
        if ($value === null) {
            return null;
        }
        if (! is_array($value) || array_is_list($value)) {
            throw new GoogleCalendarProviderException(GoogleCalendarProviderException::INVALID_RESPONSE);
        }
        $date = $this->optionalString($value, 'date', 10);
        $dateTime = $this->optionalString($value, 'dateTime', 64);
        $timeZone = $this->optionalString($value, 'timeZone', 128);
        if (($date === null) === ($dateTime === null) || ($date !== null && ! $this->isDate($date)) || ($dateTime !== null && ! $this->isDateTime($dateTime))) {
            throw new GoogleCalendarProviderException(GoogleCalendarProviderException::INVALID_RESPONSE);
        }

        return new GoogleCalendarEventTime($date, $dateTime, $timeZone);
    }

    /** @param array<string, mixed> $body */
    private function optionalDateTime(array $body, string $key): ?string
    {
        $value = $this->optionalString($body, $key, 64);
        if ($value !== null && ! $this->isDateTime($value)) {
            throw new GoogleCalendarProviderException(GoogleCalendarProviderException::INVALID_RESPONSE);
        }

        return $value;
    }

    private function isDate(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value;
    }

    private function isDateTime(string $value): bool
    {
        try {
            return (bool) preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(?:\.\d+)?(?:Z|[+-]\d\d:\d\d)$/D', $value)
                && new \DateTimeImmutable($value);
        } catch (Throwable) {
            return false;
        }
    }
}
