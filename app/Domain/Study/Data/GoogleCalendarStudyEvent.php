<?php

namespace App\Domain\Study\Data;

use App\Domain\Calendar\Data\GoogleCalendarEvent;
use App\Domain\Calendar\Data\GoogleCalendarSettings;
use App\Domain\Calendar\Models\GoogleCalendarEventMirror;
use App\Domain\Study\Support\GoogleCalendarEventIdentity;
use App\Domain\Study\Support\StudyActivitySourceKey;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Throwable;

final readonly class GoogleCalendarStudyEvent
{
    private const MAX_TITLE_LENGTH = 4096;

    private const TIMESTAMP = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,9})?(?:Z|[+-]\d{2}:\d{2})$/';

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
            $event->status,
            $event->summary,
            self::timestamp($event->start?->dateTime),
            self::timestamp($event->end?->dateTime),
            $event->start?->date !== null || $event->end?->date !== null,
            GoogleCalendarEventIdentity::sourceKey($event, $accountId, $calendarId),
            $settings,
            $now,
        );
    }

    public static function fromMirror(
        GoogleCalendarEventMirror $mirror,
        GoogleCalendarSettings $settings,
        CarbonImmutable $now,
    ): ?self {
        return self::make(
            $mirror->status,
            $mirror->title,
            $mirror->starts_at,
            $mirror->ends_at,
            $mirror->all_day,
            StudyActivitySourceKey::tryFromCanonical($mirror->source_key),
            $settings,
            $now,
        );
    }

    public function ledgerName(): string
    {
        return mb_substr($this->title, 0, 120, 'UTF-8');
    }

    private static function make(
        string $status,
        mixed $title,
        ?CarbonImmutable $start,
        ?CarbonImmutable $end,
        bool $allDay,
        ?StudyActivitySourceKey $key,
        GoogleCalendarSettings $settings,
        CarbonImmutable $now,
    ): ?self {
        $title = GoogleCalendarSettings::trimInput($title, self::MAX_TITLE_LENGTH);
        if ($status !== 'confirmed' || $allDay || ! is_string($title) || $title === ''
            || mb_strlen($title, 'UTF-8') > self::MAX_TITLE_LENGTH || $start === null || $end === null || $key === null) {
            return null;
        }
        $terms = $settings->matchedTerms($title);
        $duration = $end->getTimestampMs() - $start->getTimestampMs();
        if ($terms === [] || $duration <= 0 || $duration > StudyActivitySessionData::MAX_DURATION_MS || $end->isAfter($now)) {
            return null;
        }

        return new self($title, $start->utc(), $end->utc(), $duration, $terms, $key);
    }

    private static function timestamp(?string $value): ?CarbonImmutable
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
