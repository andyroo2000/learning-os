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

final class PreviewGoogleCalendarEventsAction
{
    private const WINDOW_DAYS = 31;

    private const PAGE_SIZE = 250;

    private const MAX_PAGES_PER_CALENDAR = 4;

    private const MAX_EVENT_REQUESTS = 8;

    private const MAX_SCANNED_EVENTS = 2000;

    private const MAX_MATCHES = 200;

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
        $token = $this->accessToken->handle($userId);
        $available = collect($this->listCalendars->handle($userId, $token)['calendars'])->keyBy('id');
        foreach ($settings->calendarIds as $calendarId) {
            if (! $available->has($calendarId)) {
                throw new GoogleCalendarSelectionException;
            }
        }

        $matches = [];
        $scanned = 0;
        $requests = 0;
        $truncated = false;
        foreach ($settings->calendarIds as $calendarId) {
            $pageToken = null;
            for ($page = 0; $page < self::MAX_PAGES_PER_CALENDAR; $page++) {
                if ($requests === self::MAX_EVENT_REQUESTS || $scanned === self::MAX_SCANNED_EVENTS) {
                    $truncated = true;
                    break 2;
                }
                // Google timeMin/timeMax select overlaps. Preserve real boundaries
                // and duration; later synchronization must not clip the event.
                $result = $this->google->events($token, $calendarId, new GoogleCalendarEventQuery(
                    timeMin: $startsAt->toIso8601ZuluString(),
                    timeMax: $generatedAt->toIso8601ZuluString(),
                    pageToken: $pageToken,
                    maxResults: min(self::PAGE_SIZE, self::MAX_SCANNED_EVENTS - $scanned),
                ));
                $requests++;
                foreach ($result->items as $event) {
                    if ($scanned === self::MAX_SCANNED_EVENTS) {
                        $truncated = true;
                        break 3;
                    }
                    $scanned++;
                    if ($event instanceof GoogleCalendarEvent) {
                        $event = GoogleCalendarStudyEvent::fromProvider(
                            $event, $settings, $connection->provider_account_id, $calendarId, $generatedAt,
                        );
                        if ($event !== null) {
                            $matches[] = [
                                'calendarId' => $calendarId, 'calendarName' => $available[$calendarId]['name'], 'title' => $event->title,
                                'startsAt' => $event->startsAt->toIso8601ZuluString(), 'endsAt' => $event->endsAt->toIso8601ZuluString(),
                                'durationMs' => $event->durationMs, 'matchedTerms' => $event->matchedTerms, '_sourceKey' => $event->sourceKey->value,
                            ];
                        }
                    }
                }
                $pageToken = $result->nextPageToken;
                if ($pageToken === null) {
                    break;
                }
                if ($page === self::MAX_PAGES_PER_CALENDAR - 1) {
                    $truncated = true;
                }
            }
        }

        usort($matches, static fn (array $a, array $b): int => ($b['startsAt'] <=> $a['startsAt'])
            ?: ($b['endsAt'] <=> $a['endsAt']) ?: ($a['calendarId'] <=> $b['calendarId']) ?: ($a['_sourceKey'] <=> $b['_sourceKey']));
        $matchedCount = count($matches);
        if ($matchedCount > self::MAX_MATCHES) {
            $truncated = true;
        }
        $matches = array_slice($matches, 0, self::MAX_MATCHES);
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

        return [
            'generatedAt' => $generatedAt->toIso8601ZuluString(), 'startsAt' => $startsAt->toIso8601ZuluString(),
            'endsAt' => $generatedAt->toIso8601ZuluString(), 'scannedEventCount' => $scanned,
            'matchedEventCount' => $matchedCount, 'truncated' => $truncated, 'matches' => $matches,
        ];
    }
}
