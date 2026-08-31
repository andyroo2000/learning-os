<?php

namespace Tests\Feature\Achievements;

use App\Domain\Achievements\Actions\GetAchievementCatalogAction;
use App\Domain\Achievements\Actions\GetAchievementProgressAction;
use App\Domain\Achievements\Models\AchievementAward;
use App\Domain\Achievements\Models\AchievementCardProjection;
use App\Domain\Achievements\Models\AchievementProgressProjection;
use App\Domain\Achievements\Support\AchievementEvaluationRateLimiter;
use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Models\Deck;
use App\Domain\Reviews\Enums\CardReviewRating;
use App\Domain\Reviews\Models\CardReviewEvent;
use App\Domain\Study\Actions\DeleteStudyActivitySessionAction;
use App\Domain\Study\Enums\StudyActivityCategory;
use App\Domain\Study\Enums\StudyActivityKind;
use App\Domain\Study\Enums\StudyActivityOrigin;
use App\Domain\Study\Enums\StudyActivitySource;
use App\Domain\Study\Models\StudyActivitySession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

class AchievementProgressApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_authoritative_progress_for_each_catalog_metric(): void
    {
        $user = User::factory()->create();
        $deck = Deck::factory()->for($user)->create();
        $stableCard = Card::factory()->for($deck)->create([
            'study_status' => CardStudyStatus::Review,
            'scheduler_state' => ['stability' => 365],
        ]);
        Card::factory()->for($deck)->create([
            'study_status' => CardStudyStatus::Review,
            'scheduler_state' => ['stability' => 364.99],
        ]);
        CardReviewEvent::factory()->for($stableCard, 'card')->count(3)->create([
            'rating' => CardReviewRating::Again,
        ]);
        $this->conversationSession($user, 60 * 60_000 + 59_999);

        $this->actingAs($user)
            ->getJson('/api/achievements/progress')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertExactJson([
                'revision' => GetAchievementCatalogAction::REVISION,
                'metricValues' => [
                    GetAchievementProgressAction::STABLE_CARD_METRIC => 1,
                    GetAchievementProgressAction::REVIEW_METRIC => 3,
                    GetAchievementProgressAction::CORRECT_RUN_METRIC => 0,
                    GetAchievementProgressAction::OLD_FRIEND_METRIC => 0,
                    GetAchievementProgressAction::GURU_CARD_METRIC => 2,
                    GetAchievementProgressAction::MASTER_CARD_METRIC => 2,
                    GetAchievementProgressAction::ENLIGHTENED_CARD_METRIC => 2,
                    GetAchievementProgressAction::BURNED_CARD_METRIC => 1,
                    GetAchievementProgressAction::CONVERSATION_HOUR_METRIC => 1,
                    GetAchievementProgressAction::LEGACY_CONVERSATION_MINUTE_METRIC => 60,
                    GetAchievementProgressAction::LISTENING_HOUR_METRIC => 0,
                    GetAchievementProgressAction::DOUBLE_FEATURE_METRIC => 0,
                    GetAchievementProgressAction::ON_REPEAT_METRIC => 0,
                ],
                'awards' => [],
            ]);
    }

    public function test_it_excludes_other_users_and_requires_authentication(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $otherDeck = Deck::factory()->for($otherUser)->create();
        $otherCard = Card::factory()->for($otherDeck)->create([
            'study_status' => CardStudyStatus::Review,
            'scheduler_state' => ['stability' => 365],
        ]);
        CardReviewEvent::factory()->for($otherCard, 'card')->count(2)->create();
        $this->conversationSession($otherUser, 90 * 60_000);

        $this->getJson('/api/achievements/progress')->assertUnauthorized();
        $this->postJson('/api/achievements/evaluate')->assertUnauthorized();

        $response = $this->actingAs($user)
            ->getJson('/api/achievements/progress')
            ->assertOk();

        $this->assertEquals([
            GetAchievementProgressAction::STABLE_CARD_METRIC => 0,
            GetAchievementProgressAction::REVIEW_METRIC => 0,
            GetAchievementProgressAction::CORRECT_RUN_METRIC => 0,
            GetAchievementProgressAction::OLD_FRIEND_METRIC => 0,
            GetAchievementProgressAction::GURU_CARD_METRIC => 0,
            GetAchievementProgressAction::MASTER_CARD_METRIC => 0,
            GetAchievementProgressAction::ENLIGHTENED_CARD_METRIC => 0,
            GetAchievementProgressAction::BURNED_CARD_METRIC => 0,
            GetAchievementProgressAction::CONVERSATION_HOUR_METRIC => 0,
            GetAchievementProgressAction::LEGACY_CONVERSATION_MINUTE_METRIC => 0,
            GetAchievementProgressAction::LISTENING_HOUR_METRIC => 0,
            GetAchievementProgressAction::DOUBLE_FEATURE_METRIC => 0,
            GetAchievementProgressAction::ON_REPEAT_METRIC => 0,
        ], $response->json('metricValues'));
        $this->assertSame([], $response->json('awards'));
    }

    public function test_progress_route_is_adjacent_to_the_catalog_with_auth_and_read_limits(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes());
        $progressRoute = $routes->first(
            static fn (LaravelRoute $route): bool => $route->uri() === 'api/achievements/progress',
        );

        $this->assertInstanceOf(LaravelRoute::class, $progressRoute);
        $this->assertSame([
            'api',
            'auth:sanctum',
            'throttle:study-compatibility-read',
        ], $progressRoute->gatherMiddleware());

        $routeOrder = $routes
            ->map(static fn (LaravelRoute $route): string => implode('|', $route->methods()).' '.$route->uri())
            ->values();
        $catalogIndex = $routeOrder->search('GET|HEAD api/achievements/catalog', strict: true);
        $progressIndex = $routeOrder->search('GET|HEAD api/achievements/progress', strict: true);
        $evaluateIndex = $routeOrder->search('POST api/achievements/evaluate', strict: true);

        $this->assertIsInt($catalogIndex);
        $this->assertIsInt($progressIndex);
        $this->assertIsInt($evaluateIndex);
        $this->assertSame($catalogIndex + 1, $progressIndex);
        $this->assertSame($progressIndex + 1, $evaluateIndex);

        $evaluateRoute = $routes->first(
            static fn (LaravelRoute $route): bool => $route->uri() === 'api/achievements/evaluate',
        );
        $this->assertInstanceOf(LaravelRoute::class, $evaluateRoute);
        $this->assertSame([
            'api',
            'auth:sanctum',
            'throttle:'.AchievementEvaluationRateLimiter::NAME,
        ], $evaluateRoute->gatherMiddleware());
    }

    public function test_it_calculates_listening_hidden_run_and_lifetime_mastery_metrics(): void
    {
        $user = User::factory()->create();
        $deck = Deck::factory()->for($user)->create();
        $oldFriend = Card::factory()->for($deck)->create();
        $masteryCard = Card::factory()->for($deck)->create([
            'study_status' => CardStudyStatus::Review,
            'scheduler_state' => ['stability' => 1],
        ]);

        CardReviewEvent::factory()->for($oldFriend, 'card')->create([
            'rating' => CardReviewRating::Good,
            'reviewed_at' => now()->subMonthsNoOverflow(7),
        ]);
        CardReviewEvent::factory()->for($oldFriend, 'card')->create([
            'rating' => CardReviewRating::Good,
            'reviewed_at' => now()->subMinutes(11),
        ]);
        CardReviewEvent::factory()->for($masteryCard, 'card')->create([
            'rating' => CardReviewRating::Good,
            'reviewed_at' => now()->subMinutes(10),
            'scheduler_state_after' => ['stability' => 365],
        ]);
        CardReviewEvent::factory()->for($masteryCard, 'card')->count(9)->sequence(
            fn ($sequence): array => [
                'rating' => CardReviewRating::Good,
                'reviewed_at' => now()->subMinutes(9 - $sequence->index),
            ],
        )->create();

        $today = now()->startOfDay()->addHours(12);
        $this->studySession($user, StudyActivityCategory::Listen, StudyActivityKind::DailyAudio, 3_600_000, $today, 'Episode 7', 3_600_000);
        $this->studySession($user, StudyActivityCategory::Conversation, StudyActivityKind::Conversation, 60_000, $today->copy()->addHour(), 'Conversation');
        $this->studySession($user, StudyActivityCategory::Listen, StudyActivityKind::DailyAudio, 0, $today, 'Daily Audio completed: Episode 7', 0);
        $this->studySession($user, StudyActivityCategory::Listen, StudyActivityKind::DailyAudio, 0, $today->copy()->subDay(), 'Daily Audio completed: Episode 7', 0);
        $this->studySession($user, StudyActivityCategory::Listen, StudyActivityKind::DailyAudio, 0, $today->copy()->subDays(2), 'Daily Audio completed: Episode 7', 0);

        $response = $this->actingAs($user)->getJson('/api/achievements/progress')->assertOk();
        $metrics = $response->json('metricValues');
        $this->assertSame(12, $metrics[GetAchievementProgressAction::CORRECT_RUN_METRIC]);
        $this->assertSame(1, $metrics[GetAchievementProgressAction::OLD_FRIEND_METRIC]);
        $this->assertSame(1, $metrics[GetAchievementProgressAction::LISTENING_HOUR_METRIC]);
        $this->assertSame(1, $metrics[GetAchievementProgressAction::DOUBLE_FEATURE_METRIC]);
        $this->assertSame(3, $metrics[GetAchievementProgressAction::ON_REPEAT_METRIC]);
        $this->assertSame(1, $metrics[GetAchievementProgressAction::BURNED_CARD_METRIC]);
        $this->assertSame(0, $metrics[GetAchievementProgressAction::STABLE_CARD_METRIC]);

        $awardIds = array_column(
            $this->actingAs($user)->postJson('/api/achievements/evaluate')->assertOk()->json('awards'),
            'id',
        );
        $this->assertContains('sound-sponge.first-echo', $awardIds);
        $this->assertContains('old-friend.old-friend', $awardIds);
        $this->assertContains('double-feature.double-feature', $awardIds);
        $this->assertContains('on-repeat.on-repeat', $awardIds);
        $this->assertContains('on-a-roll.nice-run', $awardIds);
    }

    public function test_review_metrics_do_not_shrink_when_study_material_is_archived(): void
    {
        $user = User::factory()->create();
        $deck = Deck::factory()->for($user)->create();
        $card = Card::factory()->for($deck)->create();
        CardReviewEvent::factory()->for($card, 'card')->count(2)->create([
            'rating' => CardReviewRating::Good,
        ]);

        $card->delete();
        $deck->delete();

        $metrics = $this->actingAs($user)
            ->getJson('/api/achievements/progress')
            ->assertOk()
            ->json('metricValues');
        $this->assertSame(2, $metrics[GetAchievementProgressAction::REVIEW_METRIC]);
        $this->assertSame(2, $metrics[GetAchievementProgressAction::CORRECT_RUN_METRIC]);
    }

    public function test_it_projects_new_reviews_and_persists_the_exact_threshold_timestamp(): void
    {
        $user = User::factory()->create();
        $deck = Deck::factory()->for($user)->create();
        $card = Card::factory()->for($deck)->create();

        $this->actingAs($user)->getJson('/api/achievements/progress')->assertOk();

        $firstReviewAt = now()->startOfSecond();
        CardReviewEvent::factory()->for($card, 'card')->count(100)->sequence(
            fn ($sequence): array => [
                'rating' => CardReviewRating::Good,
                'reviewed_at' => $firstReviewAt->copy()->addSeconds($sequence->index),
            ],
        )->create();
        $thresholdReviewAt = $firstReviewAt->copy()->addSeconds(99);
        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $response = $this->actingAs($user)
            ->postJson('/api/achievements/evaluate')
            ->assertOk();

        $this->assertSame(100, $response->json('metricValues')[GetAchievementProgressAction::REVIEW_METRIC]);
        $this->assertSame(
            $thresholdReviewAt->utc()->format('Y-m-d\TH:i:s.v\Z'),
            collect($response->json('awards'))->firstWhere('id', 'card-muncher.first-nibble')['earnedAt'],
        );

        $projection = AchievementProgressProjection::query()->findOrFail($user->id);
        $this->assertSame(100, $projection->metric_values[GetAchievementProgressAction::REVIEW_METRIC]);
        $this->assertSame(
            $thresholdReviewAt->utc()->format('Y-m-d\TH:i:s.v\Z'),
            $projection->threshold_reached_at[GetAchievementProgressAction::REVIEW_METRIC]['100'],
        );
        $this->assertCount(1, collect($queries)->filter(
            static fn (string $sql): bool => str_starts_with($sql, 'insert ')
                && str_contains($sql, 'achievement_card_projections'),
        ), implode("\n", $queries));
    }

    public function test_it_projects_new_study_time_without_rescanning_review_history(): void
    {
        $user = User::factory()->create();
        $deck = Deck::factory()->for($user)->create();
        $card = Card::factory()->for($deck)->create();
        CardReviewEvent::factory()->for($card, 'card')->create([
            'reviewed_at' => now()->subDay(),
        ]);
        $endedAt = now()->startOfSecond();

        $this->actingAs($user)->getJson('/api/achievements/progress')->assertOk();
        $this->conversationSession($user, 3_600_000, $endedAt);

        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $response = $this->actingAs($user)
            ->postJson('/api/achievements/evaluate')
            ->assertOk();

        $this->assertSame(1, $response->json('metricValues')[GetAchievementProgressAction::CONVERSATION_HOUR_METRIC]);
        $this->assertSame(
            $endedAt->utc()->format('Y-m-d\TH:i:s.v\Z'),
            collect($response->json('awards'))->firstWhere('id', 'roarer.first-roar')['earnedAt'],
        );
        $this->assertFalse(collect($queries)->contains(
            static fn (string $sql): bool => str_contains($sql, 'card_review_events')
                && str_contains($sql, 'order by "card_review_events"."reviewed_at"')
                && ! str_contains($sql, 'card_review_events"."created_at"'),
        ), implode("\n", $queries));
    }

    public function test_unchanged_cards_are_not_rewritten_after_projection_bootstrap(): void
    {
        $user = User::factory()->create();
        $deck = Deck::factory()->for($user)->create();
        $card = Card::factory()->for($deck)->create();

        $this->actingAs($user)->getJson('/api/achievements/progress')->assertOk();

        $cardProjection = AchievementCardProjection::query()->findOrFail($card->id);
        $this->assertTrue($cardProjection->source_updated_at->equalTo($card->updated_at));

        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $this->actingAs($user)->getJson('/api/achievements/progress')->assertOk();

        $this->assertFalse(collect($queries)->contains(
            static fn (string $sql): bool => str_contains($sql, 'achievement_card_projections')
                && (str_starts_with($sql, 'update ') || str_starts_with($sql, 'insert ')),
        ), implode("\n", $queries));
    }

    public function test_deleting_a_study_session_invalidates_and_rebuilds_the_projection(): void
    {
        $user = User::factory()->create();
        $session = $this->conversationSession($user, 3_600_000);

        $this->actingAs($user)
            ->postJson('/api/achievements/evaluate')
            ->assertOk();
        $this->assertFalse(AchievementProgressProjection::query()->findOrFail($user->id)->needs_rebuild);

        $this->assertTrue(app(DeleteStudyActivitySessionAction::class)->handle(
            $user->id,
            $session->client_session_id,
        ));
        $this->assertTrue(AchievementProgressProjection::query()->findOrFail($user->id)->needs_rebuild);

        $metrics = $this->actingAs($user)
            ->getJson('/api/achievements/progress')
            ->assertOk()
            ->json('metricValues');
        $this->assertSame(0, $metrics[GetAchievementProgressAction::CONVERSATION_HOUR_METRIC]);
        $this->assertFalse(AchievementProgressProjection::query()->findOrFail($user->id)->needs_rebuild);
    }

    public function test_evaluation_backfills_and_keeps_all_awards_in_reverse_chronological_order(): void
    {
        $user = User::factory()->create();
        $deck = Deck::factory()->for($user)->create();
        $stableAt = now()->subDays(3)->startOfSecond();
        $reviewAt = now()->subDays(2)->startOfSecond();
        $conversationAt = now()->subDay()->startOfSecond();

        Card::factory()->for($deck)->count(50)->create([
            'study_status' => CardStudyStatus::Review,
            'scheduler_state' => ['stability' => 365],
            'last_reviewed_at' => $stableAt,
        ]);
        $reviewedCard = Card::factory()->for($deck)->create();
        CardReviewEvent::factory()->for($reviewedCard, 'card')->count(100)->create([
            'reviewed_at' => $reviewAt,
            'rating' => CardReviewRating::Again,
        ]);
        $this->conversationSession($user, 60 * 60_000, $conversationAt);

        $this->actingAs($user)
            ->getJson('/api/achievements/progress')
            ->assertOk()
            ->assertJsonCount(0, 'awards');

        $response = $this->actingAs($user)
            ->postJson('/api/achievements/evaluate')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonCount(6, 'awards');

        $awardIds = array_column($response->json('awards'), 'id');
        $this->assertSame('roarer.first-roar', $awardIds[0]);
        $this->assertSame('card-muncher.first-nibble', $awardIds[1]);
        $this->assertEqualsCanonicalizing([
            'mountain-path.trailhead',
            'workshop.first-finish',
            'open-sky.first-feather',
            'archive.first-shelf',
        ], array_slice($awardIds, 2));
        $this->assertSame(
            $conversationAt->utc()->format('Y-m-d\TH:i:s.v\Z'),
            $response->json('awards.0.earnedAt'),
        );
        $this->assertSame(
            $reviewAt->utc()->format('Y-m-d\TH:i:s.v\Z'),
            $response->json('awards.1.earnedAt'),
        );
        $this->assertSame(
            $stableAt->utc()->format('Y-m-d\TH:i:s.v\Z'),
            $response->json('awards.2.earnedAt'),
        );

        $this->actingAs($user)
            ->postJson('/api/achievements/evaluate')
            ->assertOk()
            ->assertJsonCount(6, 'awards');
        $this->assertSame(6, AchievementAward::query()->where('user_id', $user->id)->count());

        CardReviewEvent::query()->where('card_id', $reviewedCard->id)->delete();
        $this->actingAs($user)
            ->getJson('/api/achievements/progress')
            ->assertOk()
            ->assertJsonCount(6, 'awards');
    }

    private function conversationSession(
        User $user,
        int $durationMs,
        ?Carbon $endedAt = null,
    ): StudyActivitySession {
        $endedAt ??= now();

        return $this->studySession(
            $user,
            StudyActivityCategory::Conversation,
            StudyActivityKind::Conversation,
            $durationMs,
            $endedAt,
            'Conversation',
        );
    }

    private function studySession(
        User $user,
        StudyActivityCategory $category,
        StudyActivityKind $activity,
        int $durationMs,
        Carbon $endedAt,
        string $name,
        ?int $audioPlaybackMs = null,
    ): StudyActivitySession {
        return StudyActivitySession::query()->forceCreate([
            'user_id' => $user->id,
            'client_session_id' => (string) Str::ulid(),
            'category' => $category,
            'activity' => $activity,
            'source' => StudyActivitySource::Manual,
            'origin' => StudyActivityOrigin::Web,
            'name' => $name,
            'started_at' => $endedAt->copy()->subMilliseconds($durationMs),
            'ended_at' => $endedAt,
            'duration_ms' => $durationMs,
            'audio_playback_ms' => $audioPlaybackMs,
        ]);
    }
}
