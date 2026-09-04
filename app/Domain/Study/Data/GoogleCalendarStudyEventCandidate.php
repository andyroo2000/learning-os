<?php

namespace App\Domain\Study\Data;

use App\Domain\Calendar\Data\GoogleCalendarEvent;
use App\Domain\Calendar\Models\GoogleCalendarEventMirror;
use App\Domain\Study\Support\GoogleCalendarEventIdentity;
use App\Domain\Study\Support\StudyActivitySourceKey;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Throwable;

final readonly class GoogleCalendarStudyEventCandidate
{
    private const TIMESTAMP = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,9})?(?:Z|[+-]\d{2}:\d{2})$/';

    private function __construct(
        public string $status,
        public mixed $title,
        public GoogleCalendarStudyEventContext $context,
    ) {}

    public static function fromProvider(
        GoogleCalendarEvent $event,
        string $accountId,
        string $calendarId,
    ): self {
        return new self(
            status: $event->status,
            title: $event->summary,
            context: new GoogleCalendarStudyEventContext(
                start: self::timestamp($event->start?->dateTime),
                end: self::timestamp($event->end?->dateTime),
                allDay: $event->start?->date !== null || $event->end?->date !== null,
                key: GoogleCalendarEventIdentity::sourceKey($event, $accountId, $calendarId),
            ),
        );
    }

    public static function fromMirror(GoogleCalendarEventMirror $mirror): self
    {
        return new self(
            status: $mirror->status,
            title: $mirror->title,
            context: new GoogleCalendarStudyEventContext(
                start: $mirror->starts_at,
                end: $mirror->ends_at,
                allDay: $mirror->all_day,
                key: StudyActivitySourceKey::tryFromCanonical($mirror->source_key),
            ),
        );
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
