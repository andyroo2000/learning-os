<?php

namespace Tests\Feature\Flashcards;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Models\Deck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CreateCardAccessApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_rejects_missing_deck(): void
    {
        $this->signIn();

        $response = $this->postJson('/api/cards', [
            'deck_id' => strtolower((string) Str::ulid()),
            'front_text' => 'ciao',
            'back_text' => 'hello',
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['deck_id']);

        $this->assertDatabaseCount('cards', 0);
    }

    public function test_it_rejects_another_users_deck(): void
    {
        $this->signIn();
        $otherDeck = Deck::factory()->create();

        $response = $this->postJson('/api/cards', [
            'deck_id' => $otherDeck->id,
            'front_text' => 'ciao',
            'back_text' => 'hello',
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['deck_id']);

        $this->assertDatabaseCount('cards', 0);
    }

    public function test_it_rejects_valid_card_id_when_requested_deck_belongs_to_another_user(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $otherDeck = Deck::factory()->create();
        $id = strtolower((string) Str::ulid());

        Card::factory()->for($deck)->create([
            'id' => $id,
            'front_text' => 'ciao',
            'back_text' => 'hello',
        ]);

        $response = $this->postJson('/api/cards', [
            'id' => $id,
            'deck_id' => $otherDeck->id,
            'front_text' => 'ciao',
            'back_text' => 'hello',
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['deck_id']);

        $this->assertDatabaseCount('cards', 1);
    }

    public function test_it_requires_authentication(): void
    {
        $deck = Deck::factory()->create();

        $response = $this->postJson('/api/cards', [
            'deck_id' => $deck->id,
            'front_text' => 'ciao',
            'back_text' => 'hello',
        ]);

        $response->assertUnauthorized();

        $this->assertDatabaseCount('cards', 0);
    }
}
