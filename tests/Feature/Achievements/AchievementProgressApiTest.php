<?php

namespace Tests\Feature\Achievements;

use App\Domain\Achievements\Actions\GetAchievementCatalogAction;
use App\Domain\Achievements\Actions\GetAchievementProgressAction;
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
        $this->conversationSession($user, 25 * 60_000 + 59_999);

        $this->actingAs($user)
            ->getJson('/api/achievements/progress')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertExactJson([
                'revision' => GetAchievementCatalogAction::REVISION,
                'metricValues' => [
                    GetAchievementProgressAction::STABLE_CARD_METRIC => 1,
                    GetAchievementProgressAction::REVIEW_METRIC => 3,
                    GetAchievementProgressAction::CONVERSATION_MINUTE_METRIC => 25,
                ],
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

        $response = $this->actingAs($user)
            ->getJson('/api/achievements/progress')
            ->assertOk();

        $this->assertSame([
            GetAchievementProgressAction::STABLE_CARD_METRIC => 0,
            GetAchievementProgressAction::REVIEW_METRIC => 0,
            GetAchievementProgressAction::CONVERSATION_MINUTE_METRIC => 0,
        ], $response->json('metricValues'));
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

        $this->assertIsInt($catalogIndex);
        $this->assertIsInt($progressIndex);
        $this->assertSame($catalogIndex + 1, $progressIndex);
    }

    private function conversationSession(User $user, int $durationMs): StudyActivitySession
    {
        return StudyActivitySession::query()->forceCreate([
            'user_id' => $user->id,
            'client_session_id' => (string) Str::ulid(),
            'category' => StudyActivityCategory::Conversation,
            'activity' => StudyActivityKind::Conversation,
            'source' => StudyActivitySource::Manual,
            'origin' => StudyActivityOrigin::Web,
            'name' => 'Conversation',
            'started_at' => now()->subMilliseconds($durationMs),
            'ended_at' => now(),
            'duration_ms' => $durationMs,
        ]);
    }
}
