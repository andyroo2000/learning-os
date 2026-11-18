<?php

namespace App\Domain\Admin\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class JournalMonthAnalytics
{
    private array $writers = [];

    private array $calendar = [];

    public function __construct(private JournalAnalyticsReader $reader) {}

    public function build(CarbonImmutable $now, int $page): array
    {
        $this->writers = [];
        $this->calendar = [];
        foreach ($this->reader->events($now->startOfMonth(), $now)->cursor() as $event) {
            $writer = $this->writers[$event->user_id] ??= new JournalWriterCosts;
            $field = $event->kind === 'original' ? 'entries' : 'followups';
            $writer->{$field}++;
            $day = CarbonImmutable::parse($event->submitted_at, 'UTC')->toDateString();
            ($this->calendar[$day] ??= new JournalWritingCounts)->add($event);
        }
        foreach ($this->reader->costs($now->startOfMonth(), $now) as $row) {
            ($this->writers[$row->user_id] ??= new JournalWriterCosts)->addUsage($row);
        }
        $elapsed = max(1, $now->startOfMonth()->diffInSeconds($now)) / 86400;

        return ['month' => $this->summary($now, $elapsed), 'writers' => $this->page($page, $elapsed, $now->daysInMonth)];
    }

    private function summary(CarbonImmutable $now, float $elapsed): array
    {
        $active = array_filter($this->writers, fn ($writer) => $writer->active());
        $sum = fn ($writers, $field) => array_sum(array_map(fn ($writer) => $writer->{$field}, $writers));
        $activeCost = $sum($active, 'inference') + $sum($active, 'tts');
        $average = count($active) === 0 ? null : $activeCost / 1000000 / count($active);
        $calendar = [];
        for ($day = $now->startOfMonth(); $day->lte($now); $day = $day->addDay()) {
            $date = $day->toDateString();
            $calendar[] = ['date' => $date] + ($this->calendar[$date] ?? new JournalWritingCounts)->toArray();
        }

        return ['start' => $now->startOfMonth()->toIso8601String(), 'asOf' => $now->toIso8601String(),
            'elapsedDays' => $elapsed, 'daysInMonth' => $now->daysInMonth, 'activeUsers' => count($active),
            'averageCostPerActiveUserUsd' => $average,
            'projectedMonthlyCostPerActiveUserUsd' => $average === null ? null : $average / $elapsed * $now->daysInMonth,
            'inferenceUsd' => $sum($this->writers, 'inference') / 1000000,
            'ttsUsd' => $sum($this->writers, 'tts') / 1000000,
            'unknownRequests' => $sum($this->writers, 'unknown'), 'calendar' => $calendar,
            'trackingSince' => $this->reader->trackingSince()];
    }

    private function page(int $page, float $elapsed, int $days): array
    {
        uksort($this->writers, fn ($a, $b) => [$this->writers[$b]->total(), $a] <=> [$this->writers[$a]->total(), $b]);
        $subset = array_slice($this->writers, ($page - 1) * 25, 25, true);
        $profiles = DB::table('admin_user_projections')->whereIn('user_id', array_keys($subset))->get()->keyBy('user_id');
        $rows = [];
        foreach ($subset as $id => $writer) {
            $profile = $profiles[$id];
            $rows[] = ['id' => $profile->convolab_id, 'name' => $profile->display_name ?: $profile->name,
                'avatarUrl' => $profile->avatar_url] + $writer->payload($elapsed, $days);
        }

        return ['data' => $rows, 'page' => $page, 'perPage' => 25, 'total' => count($this->writers)];
    }
}
