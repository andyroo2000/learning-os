<?php

namespace App\Domain\Calendar\Actions;

use App\Domain\Calendar\Data\GoogleCalendarSettings;
use App\Domain\Calendar\Models\GoogleCalendarConnection;
use Carbon\CarbonImmutable;

final class GetNextGoogleCalendarLessonAction
{
    private const MAX_CANDIDATES = 500;

    /** @return array{title:string,startsAt:string,endsAt:string}|null */
    public function handle(
        GoogleCalendarConnection $connection,
        GoogleCalendarSettings $settings,
        ?CarbonImmutable $now = null,
    ): ?array {
        if (! $settings->syncEnabled) {
            return null;
        }

        $now ??= CarbonImmutable::instance(now())->utc();
        // Bound this status-read query so a noisy shared calendar cannot make the
        // connection endpoint unbounded. A match beyond the earliest 500 future
        // events is intentionally omitted until older mirrors fall out of range.
        $candidates = $connection->eventMirrors()
            ->whereIn('calendar_id', $settings->calendarIds)
            ->where('status', 'confirmed')
            ->where('all_day', false)
            ->whereNotNull('title')
            ->whereNotNull('starts_at')
            ->where('starts_at', '>=', $now)
            ->orderBy('starts_at')
            ->orderBy('ends_at')
            ->orderBy('id')
            ->limit(self::MAX_CANDIDATES)
            ->get(['id', 'title', 'starts_at', 'ends_at']);

        foreach ($candidates as $candidate) {
            if (! is_string($candidate->title)
                || ! $settings->matchesTitle($candidate->title)
                || $candidate->starts_at === null
                || $candidate->ends_at === null
                || ! $candidate->ends_at->isAfter($candidate->starts_at)) {
                continue;
            }

            return [
                'title' => $candidate->title,
                'startsAt' => $candidate->starts_at->utc()->toIso8601ZuluString(),
                'endsAt' => $candidate->ends_at->utc()->toIso8601ZuluString(),
            ];
        }

        return null;
    }
}
