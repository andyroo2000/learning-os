<?php

namespace App\Domain\Admin\Support;

final class JournalWriterCosts
{
    public int $entries = 0;

    public int $followups = 0;

    public int $inference = 0;

    public int $tts = 0;

    public int $unknown = 0;

    public array $breakdown = [];

    public function addUsage(object $row): void
    {
        $category = $row->provider === 'fish' ? 'tts' : 'inference';
        $this->{$category} += (int) $row->micro_usd;
        $this->unknown += (int) $row->unknown_requests;
        $this->breakdown[] = ['category' => $category, 'operation' => $row->operation,
            'provider' => $row->provider, 'model' => $row->model, 'estimatedUsd' => $row->micro_usd / 1000000,
            'requests' => (int) $row->requests, 'unknownRequests' => (int) $row->unknown_requests];
    }

    public function total(): int
    {
        return $this->inference + $this->tts;
    }

    public function active(): bool
    {
        return $this->entries + $this->followups > 0;
    }

    public function payload(float $elapsedDays, int $monthDays): array
    {
        $daily = $this->total() / 1000000 / $elapsedDays;

        return ['entries' => $this->entries, 'followups' => $this->followups,
            'inferenceUsd' => $this->inference / 1000000, 'ttsUsd' => $this->tts / 1000000,
            'estimatedCostUsd' => $this->total() / 1000000, 'unknownRequests' => $this->unknown,
            'dailyAverageUsd' => $daily, 'projectedMonthlyUsd' => $daily * $monthDays,
            'breakdown' => $this->breakdown];
    }
}
