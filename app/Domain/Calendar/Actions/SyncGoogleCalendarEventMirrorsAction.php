<?php

namespace App\Domain\Calendar\Actions;

use App\Domain\Calendar\Contracts\GoogleCalendarReadTransport;
use App\Domain\Calendar\Data\GoogleCalendarEvent;
use App\Domain\Calendar\Data\GoogleCalendarEventQuery;
use App\Domain\Calendar\Data\GoogleCalendarEventTime;
use App\Domain\Calendar\Data\GoogleCalendarPage;
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

        $usage = ['pages' => 0, 'items' => 0];
        foreach ($snapshot['settings']->calendarIds as $index => $calendarId) {
            $changes = $this->calendarChanges(
                $this->calendarRequest($token, $snapshot, $calendarId, $initialTimeMin),
                $snapshot,
                $usage,
            );
            if ($changes === null) {
                return false;
            }

            if (! $this->commitCalendar($snapshot, $calendarId, $changes['cursor'], $changes['rows'], $changes['nextCursor'],
                $publishLastSynced && $index === array_key_last($snapshot['settings']->calendarIds))) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array{id:int,user:int,account:string,settings:GoogleCalendarSettings,cursors:array<string,string>,run:?string}  $snapshot
     * @param  array{token:string,account:string,calendarId:string,initialTimeMin:CarbonImmutable}  $request
     * @param  array{pages:int,items:int}  $usage
     * @return array{cursor:?string,rows:list<array<string,mixed>>,nextCursor:string}|null
     */
    private function calendarChanges(
        array $request,
        array $snapshot,
        array &$usage,
    ): ?array {
        $calendarId = $request['calendarId'];
        $cursor = $snapshot['cursors'][$calendarId] ?? null;
        try {
            [$rows, $nextCursor] = $this->fetchCalendar($request, $cursor, $usage);
        } catch (GoogleCalendarProviderException $exception) {
            if (! $this->isExpiredCursor($cursor, $exception)) {
                throw $exception;
            }
            if (! $this->resetExpiredCalendar($snapshot, $calendarId, $cursor)) {
                return null;
            }
            $cursor = null;
            [$rows, $nextCursor] = $this->fetchCalendar($request, null, $usage);
        }

        return ['cursor' => $cursor, 'rows' => $rows, 'nextCursor' => $nextCursor];
    }

    /**
     * @param  array{id:int,user:int,account:string,settings:GoogleCalendarSettings,cursors:array<string,string>,run:?string}  $snapshot
     * @return array{token:string,account:string,calendarId:string,initialTimeMin:CarbonImmutable}
     */
    private function calendarRequest(
        string $token,
        array $snapshot,
        string $calendarId,
        CarbonImmutable $initialTimeMin,
    ): array {
        return [
            'token' => $token,
            'account' => $snapshot['account'],
            'calendarId' => $calendarId,
            'initialTimeMin' => $initialTimeMin,
        ];
    }

    private function isExpiredCursor(?string $cursor, GoogleCalendarProviderException $exception): bool
    {
        return $cursor !== null
            && $exception->reason() === GoogleCalendarProviderException::SYNC_TOKEN_EXPIRED;
    }

    /** @return array{id:int,user:int,account:string,settings:GoogleCalendarSettings,cursors:array<string,string>,run:?string}|null */
    private function snapshot(int $userId, int $connectionId, bool $allowDisabled, ?string $expectedRunId): ?array
    {
        $connection = GoogleCalendarConnection::query()
            ->whereKey($connectionId)
            ->first();
        if (! $this->belongsToUser($connection, $userId)) {
            return null;
        }
        $settings = GoogleCalendarSettings::fromStored($connection->settings);
        if (! $this->canSync($connection, $settings, $allowDisabled, $expectedRunId)) {
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

    private function belongsToUser(?GoogleCalendarConnection $connection, int $userId): bool
    {
        if ($connection === null) {
            return false;
        }
        if ((int) $connection->user_id !== $userId) {
            throw (new ModelNotFoundException)->setModel(GoogleCalendarConnection::class);
        }

        return true;
    }

    private function canSync(
        GoogleCalendarConnection $connection,
        ?GoogleCalendarSettings $settings,
        bool $allowDisabled,
        ?string $expectedRunId,
    ): bool {
        return $settings !== null
            && ($allowDisabled || $settings->syncEnabled)
            && ($expectedRunId === null || $connection->sync_run_id === $expectedRunId);
    }

    /**
     * @param  array{token:string,account:string,calendarId:string,initialTimeMin:CarbonImmutable}  $request
     * @param  array{pages:int,items:int}  $usage
     * @return array{0:list<array<string,mixed>>,1:string}
     */
    private function fetchCalendar(
        array $request,
        ?string $cursor,
        array &$usage,
    ): array {
        $rows = [];
        $pageToken = null;
        $seenPageTokens = [];
        for ($page = 0; $page < self::MAX_PAGES_PER_CALENDAR; $page++) {
            $this->assertPageAvailable($usage['pages']);
            $result = $this->google->events($request['token'], $request['calendarId'], new GoogleCalendarEventQuery(
                timeMin: $cursor === null ? $request['initialTimeMin']->utc()->toIso8601ZuluString() : null,
                syncToken: $cursor,
                pageToken: $pageToken,
                maxResults: self::PAGE_SIZE,
            ));
            $usage['pages']++;
            $this->assertPageCapacity($result, count($rows), $usage['items']);
            $this->appendEvents($result->items, $rows, $usage, $request);

            [$nextPage, $nextCursor] = $this->pageTokens($result);
            if ($nextPage === null) {
                return [array_values($rows), $this->requiredCursor($nextCursor)];
            }
            $this->assertNextPage($nextPage, $nextCursor, $seenPageTokens);
            $seenPageTokens[$nextPage] = true;
            $pageToken = $nextPage;
        }

        throw $this->invalidResponse();
    }

    private function assertPageAvailable(int $totalPages): void
    {
        if ($totalPages >= self::MAX_TOTAL_PAGES) {
            throw $this->invalidResponse();
        }
    }

    private function assertPageCapacity(GoogleCalendarPage $page, int $calendarItems, int $totalItems): void
    {
        $pageItems = count($page->items);
        if (! $this->pageFits($page->items, $pageItems, $calendarItems, $totalItems)) {
            throw $this->invalidResponse();
        }
    }

    private function pageFits(array $items, int $pageItems, int $calendarItems, int $totalItems): bool
    {
        return array_is_list($items)
            && $pageItems <= self::PAGE_SIZE
            && $calendarItems + $pageItems <= self::MAX_ITEMS_PER_CALENDAR
            && $totalItems + $pageItems <= self::MAX_TOTAL_ITEMS;
    }

    /**
     * @param  list<mixed>  $events
     * @param  array<string, array<string, mixed>>  $rows
     * @param  array{pages:int,items:int}  $usage
     * @param  array{token:string,account:string,calendarId:string,initialTimeMin:CarbonImmutable}  $request
     */
    private function appendEvents(
        array $events,
        array &$rows,
        array &$usage,
        array $request,
    ): void {
        foreach ($events as $event) {
            if (! $event instanceof GoogleCalendarEvent) {
                throw $this->invalidResponse();
            }
            $row = $this->normalize($event, $request['account'], $request['calendarId']);
            $rows[$row['source_key']] = $row;
            $usage['items']++;
        }
    }

    /** @return array{0:?string,1:?string} */
    private function pageTokens(GoogleCalendarPage $page): array
    {
        return [$this->token($page->nextPageToken), $this->token($page->nextSyncToken)];
    }

    private function requiredCursor(?string $cursor): string
    {
        return $cursor ?? throw $this->invalidResponse();
    }

    /** @param array<string, true> $seenPageTokens */
    private function assertNextPage(string $nextPage, ?string $nextCursor, array $seenPageTokens): void
    {
        if ($nextCursor !== null || isset($seenPageTokens[$nextPage])) {
            throw $this->invalidResponse();
        }
    }

    /** @return array<string, mixed> */
    private function normalize(GoogleCalendarEvent $event, string $account, string $calendarId): array
    {
        $this->assertEvent($event);
        $key = GoogleCalendarEventIdentity::sourceKey($event, $account, $calendarId);
        if ($key === null) {
            throw $this->invalidResponse();
        }
        $start = $this->time($event->start);
        $end = $this->time($event->end);
        $this->assertEventTimes($event, $start, $end);
        [$startsAt, $startAllDay] = $start;
        [$endsAt, $endAllDay] = $end;
        [$originalStart] = $this->time($event->originalStartTime);
        $now = CarbonImmutable::instance(now())->utc();

        return [
            'source_key' => $key->value,
            'calendar_id' => $calendarId,
            'provider_event_id' => $event->id,
            'recurring_event_id' => $event->recurringEventId,
            'original_start_at' => $originalStart,
            'status' => $event->status,
            'title' => $this->title($event),
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'all_day' => $startsAt !== null ? $startAllDay : $event->originalStartTime?->date !== null,
            'provider_updated_at' => $this->timestamp($event->updated),
            'observed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    private function assertEvent(GoogleCalendarEvent $event): void
    {
        if (! $this->isValidEvent($event)) {
            throw $this->invalidResponse();
        }
    }

    private function isValidEvent(GoogleCalendarEvent $event): bool
    {
        return $event->id !== ''
            && strlen($event->id) <= 1024
            && in_array($event->status, ['confirmed', 'tentative', 'cancelled'], true);
    }

    /**
     * @param  array{0:?CarbonImmutable,1:bool}  $start
     * @param  array{0:?CarbonImmutable,1:bool}  $end
     */
    private function assertEventTimes(
        GoogleCalendarEvent $event,
        array $start,
        array $end,
    ): void {
        if (! $this->areValidEventTimes($event, $start, $end)) {
            throw $this->invalidResponse();
        }
    }

    /**
     * @param  array{0:?CarbonImmutable,1:bool}  $start
     * @param  array{0:?CarbonImmutable,1:bool}  $end
     */
    private function areValidEventTimes(
        GoogleCalendarEvent $event,
        array $start,
        array $end,
    ): bool {
        [$startsAt, $startAllDay] = $start;
        [$endsAt, $endAllDay] = $end;

        return ($startsAt === null) === ($endsAt === null)
            && ($startsAt === null || $startAllDay === $endAllDay)
            && ($event->status === 'cancelled' || $startsAt !== null);
    }

    private function title(GoogleCalendarEvent $event): ?string
    {
        $title = GoogleCalendarSettings::trimInput($event->summary, 4096);

        return is_string($title) && $title !== '' ? $title : null;
    }

    /** @return array{0:?CarbonImmutable,1:bool} */
    private function time(?GoogleCalendarEventTime $time): array
    {
        if ($time === null) {
            return [null, false];
        }
        if (! $this->hasOneTimeRepresentation($time)) {
            throw $this->invalidResponse();
        }

        if ($time->date !== null) {
            return [$this->date($time->date), true];
        }

        return [$this->timestamp($time->dateTime) ?? throw $this->invalidResponse(), false];
    }

    private function hasOneTimeRepresentation(GoogleCalendarEventTime $time): bool
    {
        return ($time->date === null) !== ($time->dateTime === null);
    }

    private function date(string $value): CarbonImmutable
    {
        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, 'UTC');
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw $this->invalidResponse();
        }

        return $date;
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
            if (! $this->isCursor($calendarId, $token)) {
                throw $this->invalidResponse();
            }
        }

        return $value;
    }

    private function isCursor(mixed $calendarId, mixed $token): bool
    {
        return is_string($calendarId)
            && $calendarId !== ''
            && strlen($calendarId) <= 1024
            && is_string($token)
            && $this->token($token) !== null;
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
