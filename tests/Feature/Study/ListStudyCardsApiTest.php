<?php

namespace Tests\Feature\Study;

use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Study\Support\StudyCompatibilityTrafficRateLimiter;
use App\Http\Controllers\Api\Study\ListStudyCardsController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\SetsCardStudyStatus;
use Tests\TestCase;

class ListStudyCardsApiTest extends TestCase
{
    use RefreshDatabase;
    use SetsCardStudyStatus;

    public function test_it_lists_owned_cards_with_the_compatibility_shape(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $older = $this->cardWithStudyStatus($deck, CardStudyStatus::Review);
        $newer = $this->cardWithStudyStatus($deck, CardStudyStatus::New);
        $this->cardWithStudyStatus(
            $this->deckFor(User::factory()->create()),
            CardStudyStatus::Review,
        );

        $response = $this->getJson('/api/study/cards?per_page=10');

        $response
            ->assertOk()
            ->assertJsonCount(2, 'items')
            ->assertJsonPath('items.0.id', $newer->clientId())
            ->assertJsonPath('items.0.syncId', $newer->id)
            ->assertJsonPath('items.1.id', $older->clientId())
            ->assertJsonPath('limit', 10)
            ->assertJsonPath('nextCursor', null)
            ->assertJsonStructure([
                'items' => [
                    '*' => ['id', 'syncId', 'prompt', 'answer', 'state', 'createdAt', 'updatedAt'],
                ],
                'limit',
                'nextCursor',
            ]);
    }

    public function test_it_cursor_paginates_with_stable_ordering_and_preserves_search(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $sameTime = now()->subDay();
        $cards = collect([
            $this->cardWithStudyStatus($deck, CardStudyStatus::Review),
            $this->cardWithStudyStatus($deck, CardStudyStatus::New),
            $this->cardWithStudyStatus($deck, CardStudyStatus::Learning),
        ])->each(function ($card) use ($sameTime): void {
            $card->forceFill([
                'front_text' => 'Shared search card',
                'search_text' => 'shared search card',
                'created_at' => $sameTime,
                'updated_at' => $sameTime,
            ])->saveQuietly();
        })->sortByDesc(fn ($card) => $card->id)->values();

        $firstPage = $this->getJson('/api/study/cards?per_page=2&q=shared');
        $firstPage
            ->assertOk()
            ->assertJsonPath('items.0.id', $cards[0]->clientId())
            ->assertJsonPath('items.1.id', $cards[1]->clientId());
        $cursor = $firstPage->json('nextCursor');
        $this->assertIsString($cursor);

        $secondPage = $this->getJson('/api/study/cards?per_page=2&q=shared&cursor='.urlencode($cursor));
        $secondPage
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.id', $cards[2]->clientId())
            ->assertJsonPath('nextCursor', null);
        $this->assertNotContains($cards[0]->clientId(), $secondPage->json('items.*.id'));
        $this->assertNotContains($cards[1]->clientId(), $secondPage->json('items.*.id'));
    }

    public function test_it_returns_an_empty_page_and_hides_deleted_decks(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $this->cardWithStudyStatus($deck, CardStudyStatus::Review);
        $deck->delete();

        $this->getJson('/api/study/cards')
            ->assertOk()
            ->assertExactJson([
                'items' => [],
                'limit' => 50,
                'nextCursor' => null,
            ]);
    }

    public function test_it_validates_pagination_and_requires_compatibility_read_limits(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/study/cards?per_page=0')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['per_page']);
        $this->getJson('/api/study/cards?per_page[]=10')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['per_page']);
        $this->getJson('/api/study/cards?cursor=not-a-cursor')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['cursor']);

        $route = app('router')->getRoutes()->getByAction(ListStudyCardsController::class);
        $this->assertNotNull($route);
        $this->assertContains(
            'throttle:'.StudyCompatibilityTrafficRateLimiter::NETWORK_NAME,
            $route->gatherMiddleware(),
        );
        $this->assertContains(
            'throttle:'.StudyCompatibilityTrafficRateLimiter::READ_NAME,
            $route->gatherMiddleware(),
        );
    }

    public function test_it_requires_authentication(): void
    {
        $this->getJson('/api/study/cards')->assertUnauthorized();
    }
}
