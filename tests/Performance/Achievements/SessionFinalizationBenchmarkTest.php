<?php

namespace Tests\Performance\Achievements;

use App\Domain\Achievements\Actions\EvaluateAchievementProgressAction;
use App\Domain\Achievements\Models\AchievementProgressProjection;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Models\Deck;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

// Opt-in benchmark: the default PHPUnit suites only include Feature and Unit.
class SessionFinalizationBenchmarkTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_measures_incremental_evaluation_and_history_rebuilds(): void
    {
        $user = User::factory()->create();
        $deck = Deck::factory()->for($user)->create();
        $cards = Card::factory()->for($deck)->count(500)->create()->modelKeys();
        $start = now()->subDays(20)->startOfSecond();
        $offset = 0;
        foreach ([10000, 25, 250, 1000, 0] as $count) {
            $rows = [];
            for ($i = 0; $i < $count; $i++) {
                $date = $start->copy()->addSeconds($offset + $i)->toDateTimeString();
                $rows[] = [
                    'id' => strtolower((string) Str::ulid()),
                    'card_id' => $cards[$i % count($cards)],
                    'rating' => $i % 10 === 0 ? 'again' : 'good',
                    'reviewed_at' => $date,
                    'created_at' => $date,
                    'updated_at' => $date,
                    'scheduler_state_after' => json_encode(['stability' => 20]),
                    'card_state_before' => json_encode(['content' => str_repeat('payload ', 250)]),
                ];
                if (count($rows) === 100) {
                    DB::table('card_review_events')->insert($rows);
                    $rows = [];
                }
            }
            if ($rows !== []) {
                DB::table('card_review_events')->insert($rows);
            }
            $offset += $count;
            if ($count === 0) {
                AchievementProgressProjection::query()->whereKey($user->id)->update(['needs_rebuild' => true]);
            }
            DB::enableQueryLog();
            DB::flushQueryLog();
            try {
                $started = hrtime(true);
                $result = app(EvaluateAchievementProgressAction::class)->handle($user->id);
                $elapsed = (hrtime(true) - $started) / 1000000;
                $queries = DB::getQueryLog();
            } finally {
                DB::disableQueryLog();
                DB::flushQueryLog();
            }
            fwrite(STDERR, json_encode(['new_reviews' => $count, 'total_reviews' => $offset, 'ms' => round($elapsed, 2), 'queries' => count($queries), 'sql_ms' => array_sum(array_column($queries, 'time'))])."\n");
            $this->assertSame($offset, $result['metricValues']['reviews.count']);
        }
    }
}
