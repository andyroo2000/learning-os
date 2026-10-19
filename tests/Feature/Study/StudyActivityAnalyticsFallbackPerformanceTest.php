<?php

namespace Tests\Feature\Study;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\StudyActivityBenchmarkHistory;
use Tests\TestCase;

final class StudyActivityAnalyticsFallbackPerformanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_histories_beyond_the_daily_cache_limit_keep_exact_totals_and_report_optional_metrics(): void
    {
        $large = getenv('STUDY_ANALYTICS_BENCHMARK') === '1';
        $this->travelTo(CarbonImmutable::parse(StudyActivityBenchmarkHistory::NOW));
        foreach ($large ? [2_631, 20_000] : [120] as $count) {
            $user = $this->signIn();
            $fixture = StudyActivityBenchmarkHistory::seed($user->id, $count);
            $this->moveEarliestSessionBackACentury($user->id);
            $this->measure($fixture['durationMs']);
            for ($iteration = 1; $iteration <= ($large ? 3 : 1); $iteration++) {
                $metrics = $this->measure($fixture['durationMs']);
                if ($large) {
                    fwrite(STDOUT, 'ANALYTICS_FALLBACK_BENCHMARK '.json_encode([
                        ...$fixture, 'firstSessionShiftYears' => -100, 'iteration' => $iteration, ...$metrics,
                    ], JSON_THROW_ON_ERROR).PHP_EOL);
                }
            }
        }
    }

    private function moveEarliestSessionBackACentury(int $userId): void
    {
        $row = DB::table('study_activity_sessions')->where('user_id', $userId)->orderBy('started_at')->first();
        DB::table('study_activity_sessions')->where('id', $row->id)->update([
            'started_at' => CarbonImmutable::parse($row->started_at, 'UTC')->subYears(100)->format('Y-m-d H:i:s.u'),
            'ended_at' => CarbonImmutable::parse($row->ended_at, 'UTC')->subYears(100)->format('Y-m-d H:i:s.u'),
        ]);
    }

    private function measure(int $expectedTotal): array
    {
        $started = hrtime(true);
        $response = $this->getJson('/api/study/activity-analytics?timezone=America%2FNew_York&weekStartsOn=1&adaptiveAllTime=1');
        $elapsed = (hrtime(true) - $started) / 1_000_000;
        $response->assertOk()->assertJsonCount(5, 'ranges')->assertJsonPath('ranges.4.totalMs', $expectedTotal);

        return [
            'elapsedMs' => $elapsed,
            'responseBytes' => strlen($response->getContent()),
            'payloadSha256' => hash('sha256', $response->getContent()),
        ];
    }
}
