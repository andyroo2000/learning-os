<?php

namespace App\Domain\Study\Data;

use App\Domain\Calendar\Data\GoogleCalendarEvent;
use App\Domain\Calendar\Data\GoogleCalendarSettings;
use App\Domain\Calendar\Models\GoogleCalendarEventMirror;
use App\Domain\Study\Support\StudyActivitySourceKey;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

final readonly class GoogleCalendarStudyEvent
{
    private const MAX_TITLE_LENGTH = 4096;

    private function __construct(
        public string $title,
        public CarbonImmutable $startsAt,
        public CarbonImmutable $endsAt,
        public int $durationMs,
        public array $matchedTerms,
        public StudyActivitySourceKey $sourceKey,
    ) {}

    public static function fromProvider(
        GoogleCalendarEvent $event,
        GoogleCalendarSettings $settings,
        string $accountId,
        string $calendarId,
        CarbonImmutable $now,
    ): ?self {
        return self::make(
            GoogleCalendarStudyEventCandidate::fromProvider($event, $accountId, $calendarId),
            $settings,
            $now,
        );
    }

    public static function fromMirror(
        GoogleCalendarEventMirror $mirror,
        GoogleCalendarSettings $settings,
        CarbonImmutable $now,
    ): ?self {
        return self::make(GoogleCalendarStudyEventCandidate::fromMirror($mirror), $settings, $now);
    }

    public function ledgerName(): string
    {
        return mb_substr($this->title, 0, 120, 'UTF-8');
    }

    public function deduplicationKey(): string
    {
        return hash('sha256', implode("\0", [
            mb_strtolower(Str::squish($this->title), 'UTF-8'),
            $this->startsAt->toJSON(),
            $this->endsAt->toJSON(),
        ]));
    }

    private static function make(
        GoogleCalendarStudyEventCandidate $candidate,
        GoogleCalendarSettings $settings,
        CarbonImmutable $now,
    ): ?self {
        $title = GoogleCalendarSettings::trimInput($candidate->title, self::MAX_TITLE_LENGTH);
        if (self::isInvalidCandidate($candidate, $title)) {
            return null;
        }

        $terms = $settings->matchedTerms($title);
        $duration = $candidate->context->end->getTimestampMs() - $candidate->context->start->getTimestampMs();
        if (self::isInvalidSession($terms, $duration, $candidate->context->end, $now)) {
            return null;
        }

        return new self(
            $title,
            $candidate->context->start->utc(),
            $candidate->context->end->utc(),
            $duration,
            $terms,
            $candidate->context->key,
        );
    }

    private static function isInvalidCandidate(
        GoogleCalendarStudyEventCandidate $candidate,
        mixed $title,
    ): bool {
        return $candidate->status !== 'confirmed'
            || $candidate->context->allDay
            || ! is_string($title)
            || $title === ''
            || mb_strlen($title, 'UTF-8') > self::MAX_TITLE_LENGTH
            || $candidate->context->start === null
            || $candidate->context->end === null
            || $candidate->context->key === null;
    }

    /** @param list<string> $terms */
    private static function isInvalidSession(
        array $terms,
        int $duration,
        CarbonImmutable $end,
        CarbonImmutable $now,
    ): bool {
        return $terms === []
            || $duration <= 0
            || $duration > StudyActivitySessionData::MAX_DURATION_MS
            || $end->isAfter($now);
    }
}
