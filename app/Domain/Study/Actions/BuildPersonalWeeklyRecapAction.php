<?php

namespace App\Domain\Study\Actions;

use App\Domain\Reviews\Enums\CardReviewRating;
use App\Domain\Study\Enums\StudyActivityCategory;
use App\Domain\Study\Models\StudyActivitySession;
use App\Domain\Study\Support\StudyActivityDurationAllocator;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class BuildPersonalWeeklyRecapAction
{
    public function __construct(private readonly StudyActivityDurationAllocator $durationAllocator) {}

    /** @return array<string, mixed> */
    public function handle(
        int $userId,
        DateTimeZone $timezone,
        int $weekStartsOn,
        ?CarbonImmutable $now = null,
    ): array {
        $now = ($now ?? CarbonImmutable::now($timezone))->setTimezone($timezone);
        $currentWeekStart = $this->currentWeekStart($timezone, $weekStartsOn, $now);
        $weekStart = $currentWeekStart->subWeek();
        $previousStart = $weekStart->subWeek();
        $weeks = [
            'previousWeek' => $this->emptyWeek($previousStart, $weekStart, false),
            'week' => $this->emptyWeek($weekStart, $currentWeekStart, true),
        ];

        $this->accumulateStudyTime($weeks, $userId, $timezone, $previousStart, $currentWeekStart);
        $reviewMetrics = $this->reviewMetrics($userId, $previousStart, $weekStart, $currentWeekStart);
        $introducedCounts = $this->introducedCounts($userId, $previousStart, $weekStart, $currentWeekStart);

        foreach (['previousWeek', 'week'] as $key) {
            $weeks[$key]['reviewCount'] = $reviewMetrics[$key]['count'];
            $weeks[$key]['recallRate'] = $reviewMetrics[$key]['count'] === 0
                ? null
                : round($reviewMetrics[$key]['recalled'] / $reviewMetrics[$key]['count'], 3);
            $weeks[$key]['newCardsIntroduced'] = $introducedCounts[$key];
        }

        unset(
            $weeks['previousWeek']['startsAt'],
            $weeks['previousWeek']['endsAt'],
            $weeks['previousWeek']['days'],
        );
        $days = $weeks['week']['days'];
        unset($weeks['week']['days']);
        $bestDay = null;
        foreach ($days as $date => $totalMs) {
            if ($totalMs > 0 && ($bestDay === null || $totalMs > $bestDay['totalMs'])) {
                $bestDay = ['date' => $date, 'totalMs' => $totalMs];
            }
        }
        $weeks['week']['bestDay'] = $bestDay;

        return [
            'generatedAt' => $now->utc()->format('Y-m-d\TH:i:s.u\Z'),
            'week' => $weeks['week'],
            'previousWeek' => $weeks['previousWeek'],
        ];
    }

    public function currentWeekStart(
        DateTimeZone $timezone,
        int $weekStartsOn,
        ?CarbonImmutable $now = null,
    ): CarbonImmutable {
        if ($weekStartsOn < 1 || $weekStartsOn > 7) {
            throw new InvalidArgumentException('The first weekday must be between 1 and 7.');
        }

        $now = ($now ?? CarbonImmutable::now($timezone))->setTimezone($timezone);

        // Calendar.firstWeekday uses 1=Sunday … 7=Saturday; Carbon uses 0 … 6.
        return $now->startOfWeek($weekStartsOn - 1);
    }

    /** @return array<string, mixed> */
    private function emptyWeek(CarbonImmutable $start, CarbonImmutable $end, bool $withCategories): array
    {
        $week = [
            'startsAt' => $this->wireTimestamp($start),
            'endsAt' => $this->wireTimestamp($end),
            'totalMs' => 0,
            'activeDays' => 0,
            'days' => [],
        ];
        for ($day = $start; $day->lessThan($end); $day = $day->addDay()) {
            $week['days'][$day->toDateString()] = 0;
        }
        if ($withCategories) {
            $week['categories'] = array_fill_keys(array_map(
                static fn (StudyActivityCategory $category): string => $category->value,
                StudyActivityCategory::cases(),
            ), 0);
        }

        return $week;
    }

    /** @param array<string, array<string, mixed>> $weeks */
    private function accumulateStudyTime(
        array &$weeks,
        int $userId,
        DateTimeZone $timezone,
        CarbonImmutable $start,
        CarbonImmutable $end,
    ): void {
        StudyActivitySession::query()
            ->where('user_id', $userId)
            ->where('ended_at', '>', $this->databaseTimestamp($start))
            ->where('started_at', '<', $this->databaseTimestamp($end))
            ->orderBy('started_at')
            ->cursor()
            ->each(function (StudyActivitySession $session) use (&$weeks, $timezone): void {
                $sessionStart = CarbonImmutable::parse((string) $session->getRawOriginal('started_at'), 'UTC')
                    ->setTimezone($timezone);
                $sessionEnd = CarbonImmutable::parse((string) $session->getRawOriginal('ended_at'), 'UTC')
                    ->setTimezone($timezone);
                foreach ($weeks as &$week) {
                    $weekStart = CarbonImmutable::parse($week['startsAt'])->setTimezone($timezone);
                    $weekEnd = CarbonImmutable::parse($week['endsAt'])->setTimezone($timezone);
                    for ($day = $weekStart; $day->lessThan($weekEnd); $day = $day->addDay()) {
                        $allocated = $this->durationAllocator->forWindow(
                            $session->duration_ms,
                            $sessionStart,
                            $sessionEnd,
                            $day,
                            $day->addDay(),
                        );
                        if ($allocated === 0) {
                            continue;
                        }
                        $date = $day->toDateString();
                        $week['days'][$date] += $allocated;
                        $week['totalMs'] += $allocated;
                        if (isset($week['categories'])) {
                            $week['categories'][$session->category->value] += $allocated;
                        }
                    }
                }
                unset($week);
            });

        foreach ($weeks as &$week) {
            $week['activeDays'] = count(array_filter($week['days'], static fn (int $total): bool => $total > 0));
        }
        unset($week);
    }

    /** @return array<string, array{count: int, recalled: int}> */
    private function reviewMetrics(
        int $userId,
        CarbonImmutable $start,
        CarbonImmutable $weekStart,
        CarbonImmutable $end,
    ): array {
        $metrics = [
            'previousWeek' => ['count' => 0, 'recalled' => 0],
            'week' => ['count' => 0, 'recalled' => 0],
        ];
        DB::table('card_review_events')
            ->join('cards', 'cards.id', '=', 'card_review_events.card_id')
            ->join('decks', 'decks.id', '=', 'cards.deck_id')
            ->where('decks.user_id', $userId)
            ->where('card_review_events.reviewed_at', '>=', $this->databaseTimestamp($start))
            ->where('card_review_events.reviewed_at', '<', $this->databaseTimestamp($end))
            ->orderBy('card_review_events.reviewed_at')
            ->select(['card_review_events.reviewed_at', 'card_review_events.rating'])
            ->cursor()
            ->each(function (object $event) use (&$metrics, $weekStart): void {
                $key = CarbonImmutable::parse((string) $event->reviewed_at, 'UTC')->lessThan($weekStart->utc())
                    ? 'previousWeek'
                    : 'week';
                $metrics[$key]['count']++;
                if ((string) $event->rating !== CardReviewRating::Again->value) {
                    $metrics[$key]['recalled']++;
                }
            });

        return $metrics;
    }

    /** @return array{previousWeek: int, week: int} */
    private function introducedCounts(
        int $userId,
        CarbonImmutable $start,
        CarbonImmutable $weekStart,
        CarbonImmutable $end,
    ): array {
        $counts = ['previousWeek' => 0, 'week' => 0];
        DB::table('cards')
            ->join('decks', 'decks.id', '=', 'cards.deck_id')
            ->where('decks.user_id', $userId)
            ->where('cards.introduced_at', '>=', $this->databaseTimestamp($start))
            ->where('cards.introduced_at', '<', $this->databaseTimestamp($end))
            ->orderBy('cards.introduced_at')
            ->select('cards.introduced_at')
            ->cursor()
            ->each(function (object $card) use (&$counts, $weekStart): void {
                $key = CarbonImmutable::parse((string) $card->introduced_at, 'UTC')->lessThan($weekStart->utc())
                    ? 'previousWeek'
                    : 'week';
                $counts[$key]++;
            });

        return $counts;
    }

    private function wireTimestamp(CarbonImmutable $timestamp): string
    {
        return $timestamp->utc()->format('Y-m-d\TH:i:s.u\Z');
    }

    private function databaseTimestamp(CarbonImmutable $timestamp): string
    {
        // Calendar boundaries are exact seconds. Keep the bound lexically compatible
        // with legacy second-precision rows on SQLite while still comparing correctly
        // with millisecond review timestamps on every supported database.
        return $timestamp->utc()->format('Y-m-d H:i:s');
    }
}
