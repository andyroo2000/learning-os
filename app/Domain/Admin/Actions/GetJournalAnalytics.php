<?php

namespace App\Domain\Admin\Actions;

use App\Domain\Admin\Support\JournalAnalyticsRange;
use App\Domain\Admin\Support\JournalAnalyticsReader;
use App\Domain\Admin\Support\JournalMonthAnalytics;
use App\Domain\Admin\Support\JournalWritingCounts;
use Carbon\CarbonImmutable;

final class GetJournalAnalytics
{
    public function __construct(private JournalAnalyticsReader $reader, private JournalMonthAnalytics $month) {}

    public function handle(string $period, int $page): array
    {
        $now = CarbonImmutable::now('UTC');
        $range = new JournalAnalyticsRange($period, $now);
        $buckets = $range->emptyBuckets();
        $summary = new JournalWritingCounts;
        foreach ($this->reader->events($range->start, $now)->cursor() as $event) {
            $summary->add($event);
            $buckets[$range->key(CarbonImmutable::parse($event->submitted_at, 'UTC'))]->add($event);
        }
        $rows = [];
        foreach ($buckets as $date => $counts) {
            $rows[] = ['date' => $date] + $counts->toArray();
        }
        $averages = ['averageFollowupsPerEntry' => $summary->originals === 0 ? null : $this->reader->cohortFollowups($range) / $summary->originals];

        return ['activity' => ['period' => $period, 'start' => $range->start->toDateString(),
            'end' => $now->toDateString(), 'buckets' => $rows, 'summary' => $summary->toArray() + $averages]]
            + $this->month->build($now, $page);
    }
}
