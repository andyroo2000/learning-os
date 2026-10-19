<?php

namespace Tests\Feature\Study;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\StudyActivityBenchmarkHistory;
use Tests\TestCase;

class StudyActivityAnalyticsPerformanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_real_analytics_preserves_large_history_totals_and_reports_opt_in_metrics(): void
    {
        $large = getenv('STUDY_ANALYTICS_BENCHMARK') === '1';
        $this->travelTo(CarbonImmutable::parse(StudyActivityBenchmarkHistory::NOW));
        foreach ($large ? [2_631, 20_000] : [120] as $count) {
            $user = $this->signIn();
            $fixture = StudyActivityBenchmarkHistory::seed($user->id, $count);
            $this->getJson($this->url('2026-10-19'))->assertOk();
            for ($iteration = 1; $iteration <= ($large ? 3 : 1); $iteration++) {
                foreach (['2026-10-19', '2026-10-12', '2026-09-19'] as $anchor) {
                    $metrics = $this->measure($anchor, $fixture['durationMs']);
                    if ($large) {
                        fwrite(STDOUT, 'ANALYTICS_BENCHMARK '.json_encode([
                            ...$fixture, 'iteration' => $iteration, 'anchor' => $anchor, ...$metrics,
                        ], JSON_THROW_ON_ERROR).PHP_EOL);
                    }
                }
            }
        }
    }

    /** @return array<string, int|float|string> */
    private function measure(string $anchor, int $expectedTotal): array
    {
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $started = hrtime(true);
            $response = $this->getJson($this->url($anchor));
            $elapsed = (hrtime(true) - $started) / 1_000_000;
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
        $response->assertOk()->assertJsonCount(5, 'ranges');
        $this->assertSame($expectedTotal, $response->json('ranges.4.totalMs'));
        foreach ($response->json('ranges') as $range) {
            $this->assertSame($range['totalMs'], array_sum($range['categories']));
            $this->assertSame($range['totalMs'], array_sum(array_column($range['buckets'], 'totalMs')));
        }

        return [
            'elapsedMs' => $elapsed,
            'databaseMs' => array_sum(array_column($queries, 'time')),
            'queryCount' => count($queries),
            'responseBytes' => strlen($response->getContent()),
            'payloadSha256' => hash('sha256', $response->getContent()),
            'peakMemoryBytes' => memory_get_peak_usage(true),
        ];
    }

    private function url(string $anchor): string
    {
        return '/api/study/activity-analytics?timezone=America%2FNew_York'
            .'&weekStartsOn=1&adaptiveAllTime=1&anchorDate='.$anchor;
    }
}
