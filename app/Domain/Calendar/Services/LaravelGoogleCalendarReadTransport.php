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
        if ($this->isInvalidToken($refreshToken)) {
            throw new GoogleCalendarProviderException(GoogleCalendarProviderException::RECONNECT_REQUIRED);
        }

        $response = $this->refreshResponse($refreshToken);
        $this->assertSuccessfulRefresh($response);
        $body = $this->object($response);
        $accessToken = $this->requiredString($body, 'access_token', self::MAX_TOKEN);
        $rotated = $this->optionalString($body, 'refresh_token', self::MAX_TOKEN);

        return new GoogleCalendarTokenGrant($accessToken, $this->expiresIn($body), $rotated);
    }

    public function calendars(string $accessToken, ?string $pageToken = null, int $maxResults = 100): GoogleCalendarPage
    {
        $body = $this->get($accessToken, self::API.'/users/me/calendarList', $this->query($pageToken, $maxResults, 250));
        $items = $this->items($body);

        return new GoogleCalendarPage(
            array_map($this->calendar(...), $items),
            $this->pageToken($body, 'nextPageToken'),
            $this->pageToken($body, 'nextSyncToken'),
        );
    }

    public function events(string $accessToken, string $calendarId, GoogleCalendarEventQuery $query): GoogleCalendarPage
    {
        $this->assertValidEventQuery($calendarId, $query);
        $body = $this->get(
            $accessToken,
            self::API.'/calendars/'.rawurlencode($calendarId).'/events',
            $this->eventParameters($query),
        );
        $items = $this->items($body);

        return new GoogleCalendarPage(
            array_map($this->event(...), $items),
            $this->pageToken($body, 'nextPageToken'),
            $this->pageToken($body, 'nextSyncToken'),
        );
    }

    private function refreshResponse(string $refreshToken): Response
    {
        try {
            return Http::asForm()->acceptJson()->timeout(10)->post(self::TOKEN_URL, [
                'client_id' => (string) config('services.google_calendar.client_id'),
                'client_secret' => (string) config('services.google_calendar.client_secret'),
                'grant_type' => 'refresh_token',
                'refresh_token' => $refreshToken,
            ]);
        } catch (ConnectionException) {
            throw new GoogleCalendarProviderException(GoogleCalendarProviderException::UNAVAILABLE);
        }
    }

    private function assertSuccessfulRefresh(Response $response): void
    {
        if ($response->successful()) {
            return;
        }

        $reason = $response->json('error') === 'invalid_grant'
            ? GoogleCalendarProviderException::RECONNECT_REQUIRED
            : GoogleCalendarProviderException::UNAVAILABLE;

        throw new GoogleCalendarProviderException($reason);
    }

    /** @param array<string, mixed> $body */
    private function expiresIn(array $body): int
    {
        $expiresIn = $body['expires_in'] ?? null;

        if (! is_int($expiresIn)) {
            throw new GoogleCalendarProviderException(GoogleCalendarProviderException::INVALID_RESPONSE);
        }

        if ($expiresIn < 60 || $expiresIn > 86400) {
            throw new GoogleCalendarProviderException(GoogleCalendarProviderException::INVALID_RESPONSE);
        }

        return $expiresIn;
    }

    /** @param array<string, mixed> $item */
    private function calendar(array $item): GoogleCalendar
    {
        return new GoogleCalendar(
            $this->requiredString($item, 'id', 1024),
            $this->requiredString($item, 'summary', 4096),
            $this->calendarPrimary($item),
            $this->requiredString($item, 'accessRole', 32),
        );
    }

    /** @param array<string, mixed> $item */
    private function event(array $item): GoogleCalendarEvent
    {
        return new GoogleCalendarEvent(
            $this->requiredString($item, 'id', 1024),
            $this->requiredString($item, 'status', 32),
            $this->optionalString($item, 'summary', 4096),
            $this->eventTime($item['start'] ?? null),
            $this->eventTime($item['end'] ?? null),
            $this->eventTime($item['originalStartTime'] ?? null),
            $this->optionalDateTime($item, 'updated'),
            $this->optionalString($item, 'recurringEventId', 1024),
        );
    }

    private function assertValidEventQuery(string $calendarId, GoogleCalendarEventQuery $query): void
    {
        $this->assertValidCalendarId($calendarId);

        if ($query->syncToken !== null) {
            $this->assertValidIncrementalQuery($query);

            return;
        }

        $this->assertValidEventWindow($query);
    }

    private function assertValidCalendarId(string $calendarId): void
    {
        if ($calendarId === '' || strlen($calendarId) > 1024) {
            throw new GoogleCalendarProviderException(GoogleCalendarProviderException::INVALID_REQUEST);
        }
    }

    private function assertValidEventWindow(GoogleCalendarEventQuery $query): void
    {
        if ($query->timeMin === null || ! $this->isDateTime($query->timeMin)) {
            throw new GoogleCalendarProviderException(GoogleCalendarProviderException::INVALID_REQUEST);
        }

        if ($query->timeMax !== null && ! $this->isDateTime($query->timeMax)) {
            throw new GoogleCalendarProviderException(GoogleCalendarProviderException::INVALID_REQUEST);
        }
    }

    private function assertValidIncrementalQuery(GoogleCalendarEventQuery $query): void
    {
        if ($query->timeMin !== null || $query->timeMax !== null) {
            throw new GoogleCalendarProviderException(GoogleCalendarProviderException::INVALID_REQUEST);
        }

        if ($this->isInvalidToken($query->syncToken)) {
            throw new GoogleCalendarProviderException(GoogleCalendarProviderException::INVALID_REQUEST);
        }
    }

    /** @return array<string, int|string> */
    private function eventParameters(GoogleCalendarEventQuery $eventQuery): array
    {
        $parameters = [
            'timeMin' => $eventQuery->timeMin,
            'timeMax' => $eventQuery->timeMax,
            'syncToken' => $eventQuery->syncToken,
            'singleEvents' => 'true',
            'showDeleted' => 'true',
        ];

        return $this->query($eventQuery->pageToken, $eventQuery->maxResults, 2500)
            + array_filter($parameters, fn (mixed $value): bool => $value !== null);
    }

    /** @param array<string, scalar> $query @return array<string, mixed> */
    private function get(string $token, string $url, array $query): array
    {
        if ($this->isInvalidToken($token)) {
            throw new GoogleCalendarProviderException(GoogleCalendarProviderException::INVALID_REQUEST);
        }

        try {
            $response = Http::withToken($token)->acceptJson()->timeout(10)->get($url, $query);
        } catch (ConnectionException) {
            throw new GoogleCalendarProviderException(GoogleCalendarProviderException::UNAVAILABLE);
        }

        if (! $response->successful()) {
            throw new GoogleCalendarProviderException($this->failureReason($response));
        }

        return $this->object($response);
    }

    /** @return array<string, int|string> */
    private function query(?string $pageToken, int $maxResults, int $maximum): array
    {
        if ($maxResults < 1 || $maxResults > $maximum) {
            throw new GoogleCalendarProviderException(GoogleCalendarProviderException::INVALID_REQUEST);
        }

        if ($pageToken !== null && $this->isInvalidToken($pageToken)) {
            throw new GoogleCalendarProviderException(GoogleCalendarProviderException::INVALID_REQUEST);
        }

        return array_filter(['maxResults' => $maxResults, 'pageToken' => $pageToken], fn (mixed $value): bool => $value !== null);
    }

    /** @return array<string, mixed> */
    private function object(Response $response): array
    {
        $body = $response->json();
        if (strlen($response->body()) > 2_000_000) {
            throw new GoogleCalendarProviderException(GoogleCalendarProviderException::INVALID_RESPONSE);
        }

        if (! is_array($body) || array_is_list($body)) {
            throw new GoogleCalendarProviderException(GoogleCalendarProviderException::INVALID_RESPONSE);
        }

        return $body;
    }

    /** @param array<string, mixed> $body @return list<array<string, mixed>> */
    private function items(array $body): array
    {
        $items = $body['items'] ?? [];

        if (! is_array($items) || ! array_is_list($items)) {
            throw new GoogleCalendarProviderException(GoogleCalendarProviderException::INVALID_RESPONSE);
        }

        if (count($items) > 2500) {
            throw new GoogleCalendarProviderException(GoogleCalendarProviderException::INVALID_RESPONSE);
        }

        foreach ($items as $item) {
            $this->providerObject($item);
        }

        return $items;
    }

    /** @param array<string, mixed> $body */
    private function requiredString(array $body, string $key, int $max): string
    {
        return $this->boundedString($body[$key] ?? null, $max);
    }

    /** @param array<string, mixed> $body */
    private function optionalString(array $body, string $key, int $max): ?string
    {
        if (! array_key_exists($key, $body)) {
            return null;
        }

        return $this->boundedString($body[$key], $max);
    }

    private function boundedString(mixed $value, int $max): string
    {
        if (! is_string($value)) {
            throw new GoogleCalendarProviderException(GoogleCalendarProviderException::INVALID_RESPONSE);
        }

        if ($value === '' || strlen($value) > $max) {
            throw new GoogleCalendarProviderException(GoogleCalendarProviderException::INVALID_RESPONSE);
        }

        return $value;
    }

    /** @param array<string, mixed> $body */
    private function calendarPrimary(array $body): bool
    {
        $value = $body['primary'] ?? false;

        if (! is_bool($value)) {
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

        $eventTime = $this->providerObject($value);
        $date = $this->optionalString($eventTime, 'date', 10);
        $dateTime = $this->optionalString($eventTime, 'dateTime', 64);
        $timeZone = $this->optionalString($eventTime, 'timeZone', 128);
        $this->assertExclusiveEventTime($date, $dateTime);
        $this->assertValidEventDate($date);
        $this->assertValidEventDateTime($dateTime);

        return new GoogleCalendarEventTime($date, $dateTime, $timeZone);
    }

    /** @return array<string, mixed> */
    private function providerObject(mixed $value): array
    {
        if (! is_array($value) || array_is_list($value)) {
            throw new GoogleCalendarProviderException(GoogleCalendarProviderException::INVALID_RESPONSE);
        }

        return $value;
    }

    private function assertExclusiveEventTime(?string $date, ?string $dateTime): void
    {
        $providedValues = array_filter([$date, $dateTime], fn (?string $value): bool => $value !== null);

        if (count($providedValues) !== 1) {
            throw new GoogleCalendarProviderException(GoogleCalendarProviderException::INVALID_RESPONSE);
        }
    }

    private function assertValidEventDate(?string $date): void
    {
        if ($date !== null && ! $this->isDate($date)) {
            throw new GoogleCalendarProviderException(GoogleCalendarProviderException::INVALID_RESPONSE);
        }
    }

    private function assertValidEventDateTime(?string $dateTime): void
    {
        if ($dateTime !== null && ! $this->isDateTime($dateTime)) {
            throw new GoogleCalendarProviderException(GoogleCalendarProviderException::INVALID_RESPONSE);
        }
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
        if (preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(?:\.\d+)?(?:Z|[+-]\d\d:\d\d)$/D', $value) !== 1) {
            return false;
        }

        try {
            new \DateTimeImmutable($value);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function isInvalidToken(?string $token): bool
    {
        return $token === null || $token === '' || strlen($token) > self::MAX_TOKEN;
    }

    private function failureReason(Response $response): string
    {
        return match ($response->status()) {
            401 => GoogleCalendarProviderException::RECONNECT_REQUIRED,
            410 => GoogleCalendarProviderException::SYNC_TOKEN_EXPIRED,
            default => GoogleCalendarProviderException::UNAVAILABLE,
        };
    }
}
