<?php

namespace Tests\Feature\Study;

use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Study\Actions\ListStudyCardBatchAction;
use App\Domain\Study\Support\StudyCompatibilityTrafficRateLimiter;
use App\Http\Controllers\Api\Study\ListStudyCardBatchController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\SetsCardStudyStatus;
use Tests\TestCase;

class ListStudyCardBatchApiTest extends TestCase
{
    use RefreshDatabase;
    use SetsCardStudyStatus;

    public function test_it_returns_owned_cards_in_input_order_with_compatibility_shape(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $first = $this->cardWithStudyStatus($deck, CardStudyStatus::Review);
        $second = $this->cardWithStudyStatus($deck, CardStudyStatus::New);
        $other = $this->cardWithStudyStatus(
            $this->deckFor(User::factory()->create()),
            CardStudyStatus::Review,
        );
        $missingId = strtolower((string) Str::ulid());

        $this->postJson('/api/study/cards/batch', [
            'ids' => [strtoupper($second->id), $other->id, $missingId, $first->id],
        ])
            ->assertOk()
            ->assertJsonCount(2, 'cards')
            ->assertJsonPath('cards.0.id', $second->clientId())
            ->assertJsonPath('cards.0.syncId', $second->id)
            ->assertJsonPath('cards.1.id', $first->clientId())
            ->assertJsonPath('cards.1.syncId', $first->id)
            ->assertJsonStructure([
                'cards' => [
                    '*' => ['id', 'syncId', 'prompt', 'answer', 'state', 'createdAt', 'updatedAt'],
                ],
            ]);
    }

    public function test_it_validates_batch_shape_bounds_and_distinct_ids(): void
    {
        $this->signIn();
        $id = strtolower((string) Str::ulid());

        $this->postJson('/api/study/cards/batch', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['ids']);
        $this->postJson('/api/study/cards/batch', ['ids' => null])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['ids']);
        $this->postJson('/api/study/cards/batch', ['ids' => []])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['ids']);
        $this->postJson('/api/study/cards/batch', ['ids' => [$id, strtoupper($id)]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['ids.1']);
        $this->postJson('/api/study/cards/batch', [
            'ids' => array_fill(0, ListStudyCardBatchAction::MAX_ITEMS + 1, $id),
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['ids']);
        $this->postJson('/api/study/cards/batch', ['ids' => [['nested']]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['ids.0']);
    }

    public function test_it_requires_authentication_and_uses_the_compatibility_network_limit(): void
    {
        $id = strtolower((string) Str::ulid());

        $this->postJson('/api/study/cards/batch', ['ids' => [$id]])
            ->assertUnauthorized();

        $route = app('router')->getRoutes()->getByAction(
            ListStudyCardBatchController::class,
        );

        $this->assertNotNull($route);
        $this->assertContains(
            'throttle:'.StudyCompatibilityTrafficRateLimiter::NETWORK_NAME,
            $route->gatherMiddleware(),
        );
        $this->assertNotContains(
            'throttle:'.StudyCompatibilityTrafficRateLimiter::READ_NAME,
            $route->gatherMiddleware(),
        );
    }
}
