<?php

namespace Tests\Feature\Flashcards;

use App\Domain\Flashcards\Models\Card;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CreateCardOwnedCardTombstoneApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_gone_for_owned_soft_deleted_cards_with_matching_metadata(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $id = strtolower((string) Str::ulid());

        $card = Card::factory()->for($deck)->create([
            'id' => $id,
            'front_text' => 'ciao',
            'back_text' => 'hello',
        ]);
        $card->delete();

        $response = $this->postJson('/api/cards', [
            'id' => $id,
            'deck_id' => $deck->id,
            'front_text' => 'ciao',
            'back_text' => 'hello',
        ]);

        $response
            ->assertGone()
            ->assertJsonPath('message', 'Card ID belongs to a deleted card.')
            ->assertJsonPath('reason', 'card_deleted');

        $this->assertSoftDeleted('cards', [
            'id' => $id,
            'deck_id' => $deck->id,
        ]);
    }

    public function test_it_returns_gone_for_owned_soft_deleted_cards_with_different_metadata(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $id = strtolower((string) Str::ulid());

        $card = Card::factory()->for($deck)->create([
            'id' => $id,
            'front_text' => 'ciao',
            'back_text' => 'hello',
        ]);
        $card->delete();

        $response = $this->postJson('/api/cards', [
            'id' => $id,
            'deck_id' => $deck->id,
            'front_text' => 'salve',
            'back_text' => 'hello',
        ]);

        $response
            ->assertGone()
            ->assertJsonPath('message', 'Card ID belongs to a deleted card.')
            ->assertJsonPath('reason', 'card_deleted');
    }

    public function test_it_returns_gone_for_same_user_cross_deck_soft_deleted_cards(): void
    {
        $user = $this->signIn();
        $sourceDeck = $this->deckFor($user);
        $targetDeck = $this->deckFor($user);
        $id = strtolower((string) Str::ulid());

        $card = Card::factory()->for($sourceDeck)->create([
            'id' => $id,
            'front_text' => 'ciao',
            'back_text' => 'hello',
        ]);
        $card->delete();

        $response = $this->postJson('/api/cards', [
            'id' => $id,
            'deck_id' => $targetDeck->id,
            'front_text' => 'ciao',
            'back_text' => 'hello',
        ]);

        $response
            ->assertGone()
            ->assertJsonPath('message', 'Card ID belongs to a deleted card.')
            ->assertJsonPath('reason', 'card_deleted');

        $this->assertSoftDeleted('cards', [
            'id' => $id,
            'deck_id' => $sourceDeck->id,
        ]);
    }
}
