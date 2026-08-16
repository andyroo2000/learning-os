<?php

namespace App\Domain\Calendar\Actions;

use App\Domain\Calendar\Contracts\GoogleCalendarReadTransport;
use App\Domain\Calendar\Data\GoogleCalendarEvent;
use App\Domain\Calendar\Data\GoogleCalendarEventQuery;
use App\Domain\Calendar\Data\GoogleCalendarSettings;
use App\Domain\Calendar\Exceptions\GoogleCalendarSelectionException;
use App\Domain\Calendar\Models\GoogleCalendarConnection;
use App\Domain\Calendar\Support\GoogleCalendarEventIdentity;
use App\Domain\Study\Data\StudyActivitySessionData;
use App\Domain\Study\Enums\StudyActivityOrigin;
use App\Domain\Study\Models\StudyActivitySession;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Throwable;

final class PreviewGoogleCalendarEventsAction
{
    private const WINDOW_DAYS = 31;

    private const PAGE_SIZE = 250;

    private const MAX_PAGES_PER_CALENDAR = 4;

    private const MAX_EVENT_REQUESTS = 8;

    private const MAX_SCANNED_EVENTS = 2000;

    private const MAX_MATCHES = 200;

    private const TIMESTAMP = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,9})?(?:Z|[+-]\d{2}:\d{2})$/';

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
        $available = collect($this->listCalendars->handle($userId)['calendars'])->keyBy('id');
        foreach ($settings->calendarIds as $calendarId) {
            if (! $available->has($calendarId)) {
                throw new GoogleCalendarSelectionException;
            }
        }

        $token = $this->accessToken->handle($userId);
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
                        $match = $this->match($event, $settings, $connection->provider_account_id, $calendarId, $available[$calendarId]['name'], $generatedAt);
                        if ($match !== null) {
                            $matches[] = $match;
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

    /** @return array<string, mixed>|null */
    private function match(GoogleCalendarEvent $event, GoogleCalendarSettings $settings, string $accountId, string $calendarId, string $calendarName, CarbonImmutable $now): ?array
    {
        $title = GoogleCalendarSettings::trimInput($event->summary, 4096);
        if ($event->status !== 'confirmed' || ! is_string($title) || $title === '' || mb_strlen($title, 'UTF-8') > 4096) {
            return null;
        }
        $terms = $settings->matchedTerms($title);
        $start = $this->timestamp($event->start?->dateTime);
        $end = $this->timestamp($event->end?->dateTime);
        $key = GoogleCalendarEventIdentity::sourceKey($event, $accountId, $calendarId);
        if ($terms === [] || $start === null || $end === null || $key === null) {
            return null;
        }
        $duration = $end->getTimestampMs() - $start->getTimestampMs();
        if ($duration <= 0 || $duration > StudyActivitySessionData::MAX_DURATION_MS || $end->isAfter($now)) {
            return null;
        }

        return [
            'calendarId' => $calendarId, 'calendarName' => $calendarName, 'title' => $title,
            'startsAt' => $start->utc()->toIso8601ZuluString(), 'endsAt' => $end->utc()->toIso8601ZuluString(),
            'durationMs' => $duration, 'matchedTerms' => $terms, '_sourceKey' => $key->value,
        ];
    }

    private function timestamp(?string $value): ?CarbonImmutable
    {
        if ($value === null || preg_match(self::TIMESTAMP, $value) !== 1) {
            return null;
        }
        try {
            $timestamp = new DateTimeImmutable($value);
            $errors = DateTimeImmutable::getLastErrors();

            return is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)
                ? null : CarbonImmutable::instance($timestamp);
        } catch (Throwable) {
            return null;
        }
    }
}
