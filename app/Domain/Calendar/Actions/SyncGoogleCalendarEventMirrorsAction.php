<?php

namespace App\Domain\Calendar\Actions;

use App\Domain\Calendar\Contracts\GoogleCalendarReadTransport;
use App\Domain\Calendar\Data\GoogleCalendarEvent;
use App\Domain\Calendar\Data\GoogleCalendarEventQuery;
use App\Domain\Calendar\Data\GoogleCalendarEventTime;
use App\Domain\Calendar\Data\GoogleCalendarSettings;
use App\Domain\Calendar\Exceptions\GoogleCalendarProviderException;
use App\Domain\Calendar\Models\GoogleCalendarConnection;
use App\Domain\Calendar\Models\GoogleCalendarEventMirror;
use App\Domain\Study\Support\GoogleCalendarEventIdentity;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Throwable;

final class SyncGoogleCalendarEventMirrorsAction
{
    private const PAGE_SIZE = 250;

    private const MAX_PAGES_PER_CALENDAR = 20;

    private const MAX_TOTAL_PAGES = 100;

    // 4,000 rows x 15 columns stays below PostgreSQL's 65,535 bind limit.
    private const MAX_ITEMS_PER_CALENDAR = 4000;

    private const MAX_TOTAL_ITEMS = 10_000;

    private const MAX_TOKEN_BYTES = 4096;

    private const TIMESTAMP = '/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(?:\.\d+)?(?:Z|[+-]\d\d:\d\d)$/D';

    public function __construct(
        private GetGoogleCalendarAccessTokenAction $accessToken,
        private GoogleCalendarReadTransport $google,
    ) {}

    public function handle(
        int $userId,
        GoogleCalendarConnection $connection,
        CarbonImmutable $initialTimeMin,
        bool $allowDisabled = false,
        bool $publishLastSynced = true,
        ?string $expectedRunId = null,
    ): bool {
        $before = $this->snapshot($userId, (int) $connection->getKey(), $allowDisabled, $expectedRunId);
        if ($before === null) {
            return true;
        }
        $token = $this->accessToken->handle($userId);
        $snapshot = $this->snapshot($userId, (int) $connection->getKey(), $allowDisabled, $expectedRunId);
        if ($snapshot === null || $snapshot['account'] !== $before['account']) {
            return false;
        }

        $totalPages = 0;
        $totalItems = 0;
        foreach ($snapshot['settings']->calendarIds as $index => $calendarId) {
            $cursor = $snapshot['cursors'][$calendarId] ?? null;
            try {
                [$rows, $nextCursor] = $this->fetchCalendar(
                    $token, $snapshot['account'], $calendarId, $cursor, $initialTimeMin,
                    $totalPages, $totalItems,
                );
            } catch (GoogleCalendarProviderException $exception) {
                if ($cursor === null || $exception->reason() !== GoogleCalendarProviderException::SYNC_TOKEN_EXPIRED) {
                    throw $exception;
                }
                if (! $this->resetExpiredCalendar($snapshot, $calendarId, $cursor)) {
                    return false;
                }
                $cursor = null;
                [$rows, $nextCursor] = $this->fetchCalendar(
                    $token, $snapshot['account'], $calendarId, null, $initialTimeMin,
                    $totalPages, $totalItems,
                );
            }

            if (! $this->commitCalendar($snapshot, $calendarId, $cursor, $rows, $nextCursor,
                $publishLastSynced && $index === array_key_last($snapshot['settings']->calendarIds))) {
                return false;
            }
        }

        return true;
    }

    /** @return array{id:int,user:int,account:string,settings:GoogleCalendarSettings,cursors:array<string,string>,run:?string}|null */
    private function snapshot(int $userId, int $connectionId, bool $allowDisabled, ?string $expectedRunId): ?array
    {
        $connection = GoogleCalendarConnection::query()
            ->whereKey($connectionId)
            ->first();
        if ($connection === null) {
            return null;
        }
        if ((int) $connection->user_id !== $userId) {
            throw (new ModelNotFoundException)->setModel(GoogleCalendarConnection::class);
        }
        $settings = GoogleCalendarSettings::fromStored($connection->settings);
        if ($settings === null || (! $allowDisabled && ! $settings->syncEnabled)
            || ($expectedRunId !== null && $connection->sync_run_id !== $expectedRunId)) {
            return null;
        }

        return [
            'id' => (int) $connection->id,
            'user' => (int) $connection->user_id,
            'account' => $connection->provider_account_id,
            'settings' => $settings,
            'cursors' => $this->cursors($connection->sync_cursors),
            'run' => $expectedRunId,
        ];
    }

    /** @return array{0:list<array<string,mixed>>,1:string} */
    private function fetchCalendar(
        string $token,
        string $account,
        string $calendarId,
        ?string $cursor,
        CarbonImmutable $initialTimeMin,
        int &$totalPages,
        int &$totalItems,
    ): array {
        $rows = [];
        $pageToken = null;
        $seenPageTokens = [];
        for ($page = 0; $page < self::MAX_PAGES_PER_CALENDAR; $page++) {
            if ($totalPages >= self::MAX_TOTAL_PAGES) {
                throw $this->invalidResponse();
            }
            $result = $this->google->events($token, $calendarId, new GoogleCalendarEventQuery(
                timeMin: $cursor === null ? $initialTimeMin->utc()->toIso8601ZuluString() : null,
                syncToken: $cursor,
                pageToken: $pageToken,
                maxResults: self::PAGE_SIZE,
            ));
            $totalPages++;
            if (! is_array($result->items) || ! array_is_list($result->items)
                || count($result->items) > self::PAGE_SIZE
                || count($rows) + count($result->items) > self::MAX_ITEMS_PER_CALENDAR
                || $totalItems + count($result->items) > self::MAX_TOTAL_ITEMS) {
                throw $this->invalidResponse();
            }
            foreach ($result->items as $event) {
                if (! $event instanceof GoogleCalendarEvent) {
                    throw $this->invalidResponse();
                }
                $row = $this->normalize($event, $account, $calendarId);
                $rows[$row['source_key']] = $row;
                $totalItems++;
            }

            $nextPage = $this->token($result->nextPageToken);
            $nextCursor = $this->token($result->nextSyncToken);
            if ($nextPage === null) {
                if ($nextCursor === null) {
                    throw $this->invalidResponse();
                }

                return [array_values($rows), $nextCursor];
            }
            if ($nextCursor !== null || isset($seenPageTokens[$nextPage])) {
                throw $this->invalidResponse();
            }
            $seenPageTokens[$nextPage] = true;
            $pageToken = $nextPage;
        }

        throw $this->invalidResponse();
    }

    /** @return array<string, mixed> */
    private function normalize(GoogleCalendarEvent $event, string $account, string $calendarId): array
    {
        if ($event->id === '' || strlen($event->id) > 1024
            || ! in_array($event->status, ['confirmed', 'tentative', 'cancelled'], true)) {
            throw $this->invalidResponse();
        }
        $key = GoogleCalendarEventIdentity::sourceKey($event, $account, $calendarId);
        if ($key === null) {
            throw $this->invalidResponse();
        }
        [$startsAt, $startAllDay] = $this->time($event->start);
        [$endsAt, $endAllDay] = $this->time($event->end);
        if (($startsAt === null) !== ($endsAt === null)
            || ($startsAt !== null && ($startAllDay !== $endAllDay || ! $endsAt->isAfter($startsAt)))
            || ($event->status !== 'cancelled' && $startsAt === null)) {
            throw $this->invalidResponse();
        }
        [$originalStart] = $this->time($event->originalStartTime);
        $title = GoogleCalendarSettings::trimInput($event->summary, 4096);
        $now = CarbonImmutable::instance(now())->utc();

        return [
            'source_key' => $key->value,
            'calendar_id' => $calendarId,
            'provider_event_id' => $event->id,
            'recurring_event_id' => $event->recurringEventId,
            'original_start_at' => $originalStart,
            'status' => $event->status,
            'title' => is_string($title) && $title !== '' ? $title : null,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'all_day' => $startsAt !== null ? $startAllDay : $event->originalStartTime?->date !== null,
            'provider_updated_at' => $this->timestamp($event->updated),
            'observed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /** @return array{0:?CarbonImmutable,1:bool} */
    private function time(?GoogleCalendarEventTime $time): array
    {
        if ($time === null) {
            return [null, false];
        }
        if (($time->date === null) === ($time->dateTime === null)) {
            throw $this->invalidResponse();
        }

        if ($time->date !== null) {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $time->date, 'UTC');
            if ($date === false || $date->format('Y-m-d') !== $time->date) {
                throw $this->invalidResponse();
            }

            return [$date, true];
        }

        return [$this->timestamp($time->dateTime) ?? throw $this->invalidResponse(), false];
    }

    private function timestamp(?string $value): ?CarbonImmutable
    {
        if ($value === null) {
            return null;
        }
        if (preg_match(self::TIMESTAMP, $value) !== 1) {
            throw $this->invalidResponse();
        }
        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (Throwable) {
            throw $this->invalidResponse();
        }
    }

    /** @param array{id:int,user:int,account:string,settings:GoogleCalendarSettings,cursors:array<string,string>,run:?string} $snapshot @param list<array<string,mixed>> $rows */
    private function commitCalendar(array $snapshot, string $calendarId, ?string $expectedCursor, array $rows, string $nextCursor, bool $last): bool
    {
        return DB::transaction(function () use ($snapshot, $calendarId, $expectedCursor, $rows, $nextCursor, $last): bool {
            $connection = GoogleCalendarConnection::query()
                ->whereKey($snapshot['id'])
                ->where('user_id', $snapshot['user'])
                ->lockForUpdate()
                ->first();
            if (! $this->matches($connection, $snapshot, $calendarId, $expectedCursor)) {
                return false;
            }
            foreach ($rows as &$row) {
                $row['google_calendar_connection_id'] = $connection->id;
            }
            unset($row);
            if ($rows !== []) {
                GoogleCalendarEventMirror::query()->upsert($rows, ['google_calendar_connection_id', 'source_key'], [
                    'calendar_id', 'provider_event_id', 'recurring_event_id', 'original_start_at', 'status',
                    'title', 'starts_at', 'ends_at', 'all_day', 'provider_updated_at', 'observed_at', 'updated_at',
                ]);
            }
            $cursors = $this->cursors($connection->sync_cursors);
            $cursors[$calendarId] = $nextCursor;
            $connection->forceFill(['sync_cursors' => $cursors, 'last_synced_at' => $last ? now() : $connection->last_synced_at])->save();

            return true;
        });
    }

    /** @param array{id:int,user:int,account:string,settings:GoogleCalendarSettings,cursors:array<string,string>,run:?string} $snapshot */
    private function resetExpiredCalendar(array $snapshot, string $calendarId, string $expectedCursor): bool
    {
        return DB::transaction(function () use ($snapshot, $calendarId, $expectedCursor): bool {
            $connection = GoogleCalendarConnection::query()
                ->whereKey($snapshot['id'])
                ->where('user_id', $snapshot['user'])
                ->lockForUpdate()
                ->first();
            if (! $this->matches($connection, $snapshot, $calendarId, $expectedCursor)) {
                return false;
            }
            $connection->eventMirrors()->where('calendar_id', $calendarId)->delete();
            $cursors = $this->cursors($connection->sync_cursors);
            unset($cursors[$calendarId]);
            $connection->forceFill(['sync_cursors' => $cursors])->save();

            return true;
        });
    }

    /** @param array{id:int,user:int,account:string,settings:GoogleCalendarSettings,cursors:array<string,string>,run:?string} $snapshot */
    private function matches(?GoogleCalendarConnection $connection, array $snapshot, string $calendarId, ?string $expectedCursor): bool
    {
        $settings = $connection === null ? null : GoogleCalendarSettings::fromStored($connection->settings);
        $cursors = $connection === null ? [] : $this->cursors($connection->sync_cursors);

        return $connection !== null && $connection->provider_account_id === $snapshot['account']
            && ($snapshot['run'] === null || $connection->sync_run_id === $snapshot['run'])
            && $settings?->toArray() === $snapshot['settings']->toArray()
            && in_array($calendarId, $settings->calendarIds, true)
            && ($cursors[$calendarId] ?? null) === $expectedCursor;
    }

    /** @return array<string, string> */
    private function cursors(mixed $value): array
    {
        if ($value === null) {
            return [];
        }
        if (! is_array($value) || array_is_list($value)) {
            throw $this->invalidResponse();
        }
        foreach ($value as $calendarId => $token) {
            if (! is_string($calendarId) || $calendarId === '' || strlen($calendarId) > 1024
                || ! is_string($token) || $this->token($token) === null) {
                throw $this->invalidResponse();
            }
        }

        return $value;
    }

    private function token(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if ($value === '' || strlen($value) > self::MAX_TOKEN_BYTES) {
            throw $this->invalidResponse();
        }

        return $value;
    }

    private function invalidResponse(): GoogleCalendarProviderException
    {
        return new GoogleCalendarProviderException(GoogleCalendarProviderException::INVALID_RESPONSE);
    }
}
