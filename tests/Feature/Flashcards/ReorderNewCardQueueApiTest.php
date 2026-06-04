<?php

namespace Tests\Feature\Flashcards;

use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SetsCardStudyStatus;
use Tests\TestCase;

class ReorderNewCardQueueApiTest extends TestCase
{
    use RefreshDatabase;
    use SetsCardStudyStatus;

    public function test_it_requires_authentication(): void
    {
        $response = $this->postJson('/api/cards/new/reorder', [
            'card_ids' => [strtolower((string) str()->ulid())],
        ]);

        $response->assertUnauthorized();
    }

    public function test_it_reorders_the_authenticated_users_new_card_queue(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $firstCard = $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'new_queue_position' => 1,
        ]);
        $secondCard = $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'new_queue_position' => 2,
        ]);

        $response = $this->postJson('/api/cards/new/reorder', [
            'card_ids' => [strtoupper($secondCard->id), $firstCard->id],
        ]);

        $response
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $secondCard->id)
            ->assertJsonPath('data.0.new_queue_position', 1)
            ->assertJsonPath('data.1.id', $firstCard->id)
            ->assertJsonPath('data.1.new_queue_position', 2);

        $this->assertDatabaseHas('cards', [
            'id' => $secondCard->id,
            'new_queue_position' => 1,
        ]);
        $this->assertDatabaseHas('cards', [
            'id' => $firstCard->id,
            'new_queue_position' => 2,
        ]);
    }

    public function test_it_rejects_missing_empty_duplicate_and_malformed_card_ids(): void
    {
        $this->signIn();
        $cardId = strtolower((string) str()->ulid());

        $this->postJson('/api/cards/new/reorder', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['card_ids']);

        $this->postJson('/api/cards/new/reorder', ['card_ids' => []])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['card_ids']);

        $this->postJson('/api/cards/new/reorder', ['card_ids' => [$cardId, strtoupper($cardId)]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['card_ids.1']);

        $this->postJson('/api/cards/new/reorder', ['card_ids' => ['not-a-ulid']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['card_ids.0']);

        $this->postJson('/api/cards/new/reorder', ['card_ids' => [['nested']]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['card_ids.0']);
    }

    public function test_it_rejects_cross_user_deleted_deck_deleted_card_and_non_new_cards(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $newCard = $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'new_queue_position' => 1,
        ]);
        $reviewCard = $this->cardWithStudyStatus($deck, CardStudyStatus::Review, [
            'new_queue_position' => 2,
        ]);
        $otherUserCard = $this->cardWithStudyStatus($this->deckFor(User::factory()->create()), CardStudyStatus::New, [
            'new_queue_position' => 3,
        ]);
        $deletedDeck = $this->deckFor($user);
        $deletedDeckCard = $this->cardWithStudyStatus($deletedDeck, CardStudyStatus::New, [
            'new_queue_position' => 4,
        ]);
        $deletedDeck->delete();
        $deletedCard = $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'new_queue_position' => 5,
        ]);
        $deletedCard->delete();

        foreach ([$reviewCard, $otherUserCard, $deletedDeckCard, $deletedCard] as $invalidCard) {
            $this->postJson('/api/cards/new/reorder', [
                'card_ids' => [$newCard->id, $invalidCard->id],
            ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['card_ids']);
        }

        $this->assertDatabaseHas('cards', [
            'id' => $newCard->id,
            'new_queue_position' => 1,
        ]);
    }
}
