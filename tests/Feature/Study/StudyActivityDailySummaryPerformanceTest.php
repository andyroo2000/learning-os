<?php

namespace Tests\Feature\Study;

use App\Domain\Study\Support\StudyActivityDailySummaries;
use App\Domain\Study\Support\StudyActivityDailySummaryBuilder;
use App\Domain\Study\Support\StudyActivitySummaryWindow;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\StudyActivityBenchmarkHistory;
use Tests\TestCase;

class StudyActivityDailySummaryPerformanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_daily_summary_cache_matches_raw_history_without_warm_ledger_reads_or_writes(): void
    {
        $large = getenv('STUDY_ANALYTICS_BENCHMARK') === '1';
        $now = CarbonImmutable::parse(StudyActivityBenchmarkHistory::NOW)->setTimezone('America/New_York');
        $this->travelTo($now);
        $window = new StudyActivitySummaryWindow($now->getTimezone(), $now->subDays(1_460), $now, $now);
        foreach ($large ? [2_631, 20_000] : [120] as $count) {
            $user = $this->signIn();
            $fixture = StudyActivityBenchmarkHistory::seed($user->id, $count);
            // Prime autoload/database paths before recording any of the three phases.
            app(StudyActivityDailySummaries::class)->forWindow($user->id, $window);
            for ($iteration = 1; $iteration <= ($large ? 3 : 1); $iteration++) {
                $measurements = $this->measurePhases($user->id, $window);
                $this->assertEquivalent($measurements, $fixture['durationMs']);
                if ($large) {
                    $this->report($measurements, [...$fixture, 'iteration' => $iteration]);
                }
            }
        }
    }

    private function measurePhases(int $userId, StudyActivitySummaryWindow $window): array
    {
        $raw = $this->measure(fn () => app(StudyActivityDailySummaryBuilder::class)->build($userId, $window->days()));
        // Cache reset is setup work and is deliberately excluded from cold timing.
        DB::table('study_activity_daily_summaries')->where('user_id', $userId)->delete();
        $cold = $this->measure(fn () => app(StudyActivityDailySummaries::class)->forWindow($userId, $window));
        $warm = $this->measure(fn () => app(StudyActivityDailySummaries::class)->forWindow($userId, $window));
        $cold['data'] = array_column($cold['data'], 'categories', 'date');
        $warm['data'] = array_column($warm['data'], 'categories', 'date');

        return ['raw' => $raw, 'cold' => $cold, 'warm' => $warm];
    }

    private function measure(callable $operation): array
    {
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $started = hrtime(true);
            $data = $operation();
            $elapsed = (hrtime(true) - $started) / 1_000_000;
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }

        return [
            'data' => $data,
            'elapsedMs' => $elapsed,
            'databaseMs' => array_sum(array_column($queries, 'time')),
            'queryCount' => count($queries),
            'ledgerQueries' => count(array_filter($queries, static fn (array $query): bool => str_contains($query['query'], 'study_activity_sessions'))),
            'writes' => count(array_filter($queries, static fn (array $query): bool => preg_match('/^\s*(insert|update|delete)\b/i', $query['query']) === 1)),
            'peakMemoryBytes' => memory_get_peak_usage(true),
        ];
    }

    private function assertEquivalent(array $measurements, int $expectedTotal): void
    {
        $raw = $measurements['raw']['data'];
        $this->assertSame($raw, $measurements['cold']['data']);
        $this->assertSame($raw, $measurements['warm']['data']);
        $this->assertSame($expectedTotal, array_sum(array_map(array_sum(...), $raw)));
        $this->assertSame(1, $measurements['raw']['ledgerQueries']);
        $this->assertSame(1, $measurements['cold']['ledgerQueries']);
        $this->assertSame(0, $measurements['warm']['ledgerQueries']);
        $this->assertSame(0, $measurements['warm']['writes']);
    }

    private function report(array $measurements, array $fixture): void
    {
        foreach ($measurements as $phase => $measurement) {
            $data = $measurement['data'];
            unset($measurement['data']);
            fwrite(STDOUT, 'DAILY_SUMMARY_BENCHMARK '.json_encode([
                ...$fixture, 'phase' => $phase, ...$measurement,
                'summaryDays' => count($data),
                'payloadSha256' => hash('sha256', json_encode($data, JSON_THROW_ON_ERROR)),
            ], JSON_THROW_ON_ERROR).PHP_EOL);
        }
    }
}
