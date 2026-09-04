<?php

namespace App\Domain\Study\Actions;

use App\Domain\Calendar\Actions\GetGoogleCalendarAccessTokenAction;
use App\Domain\Calendar\Actions\ListReadableGoogleCalendarsAction;
use App\Domain\Calendar\Contracts\GoogleCalendarReadTransport;
use App\Domain\Calendar\Data\GoogleCalendarEvent;
use App\Domain\Calendar\Data\GoogleCalendarEventQuery;
use App\Domain\Calendar\Data\GoogleCalendarSettings;
use App\Domain\Calendar\Exceptions\GoogleCalendarSelectionException;
use App\Domain\Calendar\Models\GoogleCalendarConnection;
use App\Domain\Study\Data\GoogleCalendarStudyEvent;
use App\Domain\Study\Enums\StudyActivityOrigin;
use App\Domain\Study\Models\StudyActivitySession;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

final class PreviewGoogleCalendarEventsAction
{
    private const WINDOW_DAYS = 31;

    private const PAGE_SIZE = 250;

    private const MAX_PAGES_PER_CALENDAR = 4;

    private const MAX_EVENT_REQUESTS = 8;

    private const MAX_SCANNED_EVENTS = 2000;

    private const MAX_MATCHES = 200;

    private string $token;

    private GoogleCalendarSettings $settings;

    private string $providerAccountId;

    private Collection $availableCalendars;

    private CarbonImmutable $startsAt;

    private CarbonImmutable $generatedAt;

    public function __construct(
        private ListReadableGoogleCalendarsAction $listCalendars,
        private GetGoogleCalendarAccessTokenAction $accessToken,
        private GoogleCalendarReadTransport $google,
    ) {}

    /** @return array<string, mixed> */
    public function handle(int $userId, GoogleCalendarSettings $settings): array
    {
        $generatedAt = CarbonImmutable::instance(now())->utc();
        $startsAt = $generatedAt->subDays(self::WINDOW_DAYS);
        $connection = GoogleCalendarConnection::query()->where('user_id', $userId)->firstOrFail();
        $this->token = $this->accessToken->handle($userId);
        $this->settings = $settings;
        $this->providerAccountId = $connection->provider_account_id;
        $this->availableCalendars = collect(
            $this->listCalendars->handle($userId, $this->token)['calendars'],
        )->keyBy('id');
        $this->startsAt = $startsAt;
        $this->generatedAt = $generatedAt;
        $this->assertCalendarsAvailable();
        $scan = $this->scanCalendars();
        $matches = $scan['matches'];
        $this->sortMatches($matches);
        $matchedCount = count($matches);
        $truncated = $scan['truncated'] || $matchedCount > self::MAX_MATCHES;
        $matches = array_slice($matches, 0, self::MAX_MATCHES);
        $this->markAlreadySynced($userId, $matches);

        return [
            'generatedAt' => $generatedAt->toIso8601ZuluString(), 'startsAt' => $startsAt->toIso8601ZuluString(),
            'endsAt' => $generatedAt->toIso8601ZuluString(), 'scannedEventCount' => $scan['scanned'],
            'matchedEventCount' => $matchedCount, 'truncated' => $truncated, 'matches' => $matches,
        ];
    }

    private function assertCalendarsAvailable(): void
    {
        foreach ($this->settings->calendarIds as $calendarId) {
            if (! $this->availableCalendars->has($calendarId)) {
                throw new GoogleCalendarSelectionException;
            }
        }
    }

    /** @return array{matches: list<array<string, mixed>>, scanned: int, requests: int, truncated: bool} */
    private function scanCalendars(): array
    {
        $scan = ['matches' => [], 'scanned' => 0, 'requests' => 0, 'truncated' => false];
        foreach ($this->settings->calendarIds as $calendarId) {
            if (! $this->scanCalendar($scan, $calendarId)) {
                break;
            }
        }

        return $scan;
    }

    /** @param array{matches: list<array<string, mixed>>, scanned: int, requests: int, truncated: bool} $scan */
    private function scanCalendar(
        array &$scan,
        string $calendarId,
    ): bool {
        $pageToken = null;
        for ($page = 0; $page < self::MAX_PAGES_PER_CALENDAR; $page++) {
            if ($scan['requests'] === self::MAX_EVENT_REQUESTS || $scan['scanned'] === self::MAX_SCANNED_EVENTS) {
                $scan['truncated'] = true;

                return false;
            }
            // Google timeMin/timeMax select overlaps. Preserve real boundaries
            // and duration; later synchronization must not clip the event.
            $result = $this->google->events($this->token, $calendarId, new GoogleCalendarEventQuery(
                timeMin: $this->startsAt->toIso8601ZuluString(),
                timeMax: $this->generatedAt->toIso8601ZuluString(),
                pageToken: $pageToken,
                maxResults: min(self::PAGE_SIZE, self::MAX_SCANNED_EVENTS - $scan['scanned']),
            ));
            $scan['requests']++;
            if (! $this->scanEvents($scan, $result->items, $calendarId)) {
                return false;
            }
            $pageToken = $result->nextPageToken;
            if ($pageToken === null) {
                return true;
            }
            if ($page === self::MAX_PAGES_PER_CALENDAR - 1) {
                $scan['truncated'] = true;
            }
        }

        return true;
    }

    /**
     * @param  array{matches: list<array<string, mixed>>, scanned: int, requests: int, truncated: bool}  $scan
     * @param  list<mixed>  $events
     */
    private function scanEvents(array &$scan, array $events, string $calendarId): bool
    {
        foreach ($events as $event) {
            if ($scan['scanned'] === self::MAX_SCANNED_EVENTS) {
                $scan['truncated'] = true;

                return false;
            }
            $scan['scanned']++;
            $match = $this->calendarMatch($event, $calendarId);
            if ($match !== null) {
                $scan['matches'][] = $match;
            }
        }

        return true;
    }

    /** @return array<string, mixed>|null */
    private function calendarMatch(
        mixed $event,
        string $calendarId,
    ): ?array {
        if (! $event instanceof GoogleCalendarEvent) {
            return null;
        }
        $event = GoogleCalendarStudyEvent::fromProvider(
            $event, $this->settings, $this->providerAccountId, $calendarId, $this->generatedAt,
        );
        if ($event === null) {
            return null;
        }

        return [
            'calendarId' => $calendarId, 'calendarName' => $this->availableCalendars[$calendarId]['name'], 'title' => $event->title,
            'startsAt' => $event->startsAt->toIso8601ZuluString(), 'endsAt' => $event->endsAt->toIso8601ZuluString(),
            'durationMs' => $event->durationMs, 'matchedTerms' => $event->matchedTerms, '_sourceKey' => $event->sourceKey->value,
        ];
    }

    /** @param list<array<string, mixed>> $matches */
    private function sortMatches(array &$matches): void
    {
        usort($matches, static fn (array $a, array $b): int => ($b['startsAt'] <=> $a['startsAt'])
            ?: ($b['endsAt'] <=> $a['endsAt']) ?: ($a['calendarId'] <=> $b['calendarId']) ?: ($a['_sourceKey'] <=> $b['_sourceKey']));
    }

    /** @param list<array<string, mixed>> $matches */
    private function markAlreadySynced(int $userId, array &$matches): void
    {
        $sourceKeys = array_values(array_unique(array_column($matches, '_sourceKey')));
        $synced = StudyActivitySession::query()
            ->where('user_id', $userId)
            ->where('origin', StudyActivityOrigin::GoogleCalendar)
            ->whereIn('source_key', $sourceKeys)
            ->pluck('source_key')->flip();
        foreach ($matches as &$match) {
            $match['alreadySynced'] = $synced->has($match['_sourceKey']);
            unset($match['_sourceKey']);
        }
    }
}
