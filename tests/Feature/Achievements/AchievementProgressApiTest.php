<?php

namespace Tests\Feature\Achievements;

use App\Domain\Achievements\Actions\GetAchievementCatalogAction;
use App\Domain\Achievements\Actions\GetAchievementProgressAction;
use App\Domain\Achievements\Models\AchievementAward;
use App\Domain\Achievements\Support\AchievementEvaluationRateLimiter;
use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Models\Deck;
use App\Domain\Reviews\Models\CardReviewEvent;
use App\Domain\Study\Enums\StudyActivityCategory;
use App\Domain\Study\Enums\StudyActivityKind;
use App\Domain\Study\Enums\StudyActivityOrigin;
use App\Domain\Study\Enums\StudyActivitySource;
use App\Domain\Study\Models\StudyActivitySession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Carbon;
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
        CardReviewEvent::factory()->for($stableCard, 'card')->count(3)->create();
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
                    GetAchievementProgressAction::CONVERSATION_HOUR_METRIC => 1,
                    GetAchievementProgressAction::LEGACY_CONVERSATION_MINUTE_METRIC => 60,
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

        $this->assertSame([
            GetAchievementProgressAction::STABLE_CARD_METRIC => 0,
            GetAchievementProgressAction::REVIEW_METRIC => 0,
            GetAchievementProgressAction::CONVERSATION_HOUR_METRIC => 0,
            GetAchievementProgressAction::LEGACY_CONVERSATION_MINUTE_METRIC => 0,
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

    public function test_evaluation_backfills_and_keeps_all_awards_in_reverse_chronological_order(): void
    {
        $user = User::factory()->create();
        $deck = Deck::factory()->for($user)->create();
        $stableAt = now()->subDays(3)->startOfSecond();
        $reviewAt = now()->subDays(2)->startOfSecond();
        $conversationAt = now()->subDay()->startOfSecond();

        Card::factory()->for($deck)->count(25)->create([
            'study_status' => CardStudyStatus::Review,
            'scheduler_state' => ['stability' => 365],
            'last_reviewed_at' => $stableAt,
        ]);
        $reviewedCard = Card::factory()->for($deck)->create();
        CardReviewEvent::factory()->for($reviewedCard, 'card')->count(100)->create([
            'reviewed_at' => $reviewAt,
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
            ->assertJsonCount(3, 'awards');

        $this->assertSame([
            'roarer.first-roar',
            'card-muncher.first-nibble',
            'yearfire.first-ember',
        ], array_column($response->json('awards'), 'id'));
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
            ->assertJsonCount(3, 'awards');
        $this->assertSame(3, AchievementAward::query()->where('user_id', $user->id)->count());

        CardReviewEvent::query()->where('card_id', $reviewedCard->id)->delete();
        $this->actingAs($user)
            ->getJson('/api/achievements/progress')
            ->assertOk()
            ->assertJsonCount(3, 'awards');
    }

    private function conversationSession(
        User $user,
        int $durationMs,
        ?Carbon $endedAt = null,
    ): StudyActivitySession {
        $endedAt ??= now();

        return StudyActivitySession::query()->forceCreate([
            'user_id' => $user->id,
            'client_session_id' => (string) Str::ulid(),
            'category' => StudyActivityCategory::Conversation,
            'activity' => StudyActivityKind::Conversation,
            'source' => StudyActivitySource::Manual,
            'origin' => StudyActivityOrigin::Web,
            'name' => 'Conversation',
            'started_at' => $endedAt->copy()->subMilliseconds($durationMs),
            'ended_at' => $endedAt,
            'duration_ms' => $durationMs,
        ]);
    }
}
