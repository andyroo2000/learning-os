<?php

namespace Tests\Feature\Study;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\StudyActivityBenchmarkHistory;
use Tests\TestCase;

final class StudyActivitySummaryAnalyticsPerformanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_real_http_cold_and_warm_cache_paths_preserve_payloads_and_bound_ledger_work(): void
    {
        $large = getenv('STUDY_ANALYTICS_BENCHMARK') === '1';
        $this->travelTo(CarbonImmutable::parse(StudyActivityBenchmarkHistory::NOW));
        foreach ($large ? [2_631, 20_000] : [120] as $count) {
            $user = $this->signIn();
            $fixture = StudyActivityBenchmarkHistory::seed($user->id, $count);
            $reference = $this->getJson($this->url(['anchorDate' => '2026-10-12']))->assertOk()->json();
            for ($iteration = 1; $iteration <= ($large ? 3 : 1); $iteration++) {
                $measurements = $this->measurePhases($user->id);
                $this->assertEquivalent($measurements, $reference, $fixture['durationMs']);
                if ($large) {
                    $this->report($measurements, [...$fixture, 'iteration' => $iteration]);
                }
            }
        }
    }

    private function measurePhases(int $userId): array
    {
        $measurements = [];
        foreach (['full' => [], 'week' => ['ranges' => ['week'], 'anchorDate' => '2026-10-12']] as $label => $query) {
            // Reset only derived state, outside the cold timing interval.
            DB::table('study_activity_daily_summaries')->where('user_id', $userId)->delete();
            $measurements[$label.'Cold'] = $this->measure($query);
            $measurements[$label.'Warm'] = $this->measure($query);
        }

        return $measurements;
    }

    private function measure(array $query): array
    {
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $started = hrtime(true);
            $response = $this->getJson($this->url($query));
            $elapsed = (hrtime(true) - $started) / 1_000_000;
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
        $response->assertOk();

        return [
            'data' => $response->json(),
            'elapsedMs' => $elapsed,
            'databaseMs' => array_sum(array_column($queries, 'time')),
            'queryCount' => count($queries),
            'ledgerQueries' => count(array_filter($queries, static fn (array $entry): bool => str_contains($entry['query'], 'study_activity_sessions'))),
            'writes' => count(array_filter($queries, static fn (array $entry): bool => preg_match('/^\s*(insert|update|delete)\b/i', $entry['query']) === 1)),
            'responseBytes' => strlen($response->getContent()),
            'payloadSha256' => hash('sha256', $response->getContent()),
            'peakMemoryBytes' => memory_get_peak_usage(true),
        ];
    }

    private function assertEquivalent(array $measurements, array $reference, int $expectedTotal): void
    {
        $this->assertSame($measurements['fullCold']['payloadSha256'], $measurements['fullWarm']['payloadSha256']);
        $this->assertSame($expectedTotal, $measurements['fullWarm']['data']['ranges'][4]['totalMs']);
        $this->assertSame($measurements['weekCold']['payloadSha256'], $measurements['weekWarm']['payloadSha256']);
        $this->assertSame([$reference['ranges'][1]], $measurements['weekWarm']['data']['ranges']);
        $this->assertSame(1, $measurements['weekCold']['ledgerQueries']);
        $this->assertSame(0, $measurements['weekWarm']['ledgerQueries']);
        $this->assertSame(0, $measurements['weekWarm']['writes']);
        $this->assertSame(3, $measurements['fullWarm']['ledgerQueries']);
        $this->assertSame(0, $measurements['fullWarm']['writes']);
    }

    private function report(array $measurements, array $fixture): void
    {
        foreach ($measurements as $phase => $measurement) {
            unset($measurement['data']);
            fwrite(STDOUT, 'SUMMARY_ANALYTICS_BENCHMARK '.json_encode([
                ...$fixture, 'phase' => $phase, ...$measurement,
            ], JSON_THROW_ON_ERROR).PHP_EOL);
        }
    }

    private function url(array $query): string
    {
        return '/api/study/activity-analytics?'.http_build_query(array_merge([
            'timezone' => 'America/New_York', 'weekStartsOn' => 1,
            'adaptiveAllTime' => 1, 'anchorDate' => '2026-10-19',
        ], $query));
    }
}
