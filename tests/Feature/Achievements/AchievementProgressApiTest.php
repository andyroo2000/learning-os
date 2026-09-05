<?php

namespace Tests\Feature\Achievements;

use App\Domain\Achievements\Actions\GetAchievementCatalogAction;
use App\Domain\Achievements\Actions\GetAchievementProgressAction;
use App\Domain\Achievements\Support\AchievementEvaluationRateLimiter;
use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Models\Deck;
use App\Domain\Reviews\Enums\CardReviewRating;
use App\Domain\Reviews\Models\CardReviewEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;
use Tests\Support\Achievements\AchievementStudySessionFixture;
use Tests\Support\Achievements\BuildsAchievementStudySessions;
use Tests\TestCase;

class AchievementProgressApiTest extends TestCase
{
    use BuildsAchievementStudySessions;
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
        $this->studySession($user, AchievementStudySessionFixture::dailyAudio(3_600_000, $today, 'Episode 7', 3_600_000));
        $this->conversationSession($user, 60_000, $today->copy()->addHour());
        $this->studySession($user, AchievementStudySessionFixture::dailyAudio(0, $today, 'Daily Audio completed: Episode 7', 0));
        $this->studySession($user, AchievementStudySessionFixture::dailyAudio(0, $today->copy()->subDay(), 'Daily Audio completed: Episode 7', 0));
        $this->studySession($user, AchievementStudySessionFixture::dailyAudio(0, $today->copy()->subDays(2), 'Daily Audio completed: Episode 7', 0));

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
}
