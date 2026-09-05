<?php

namespace Tests\Feature\Flashcards;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ListDeckCardsBoundaryApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_not_found_for_a_soft_deleted_deck(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);

        $deck->delete();

        $response = $this->getJson("/api/decks/{$deck->id}/cards");

        $response->assertNotFound();
    }

    public function test_it_hides_another_users_deck(): void
    {
        $this->signIn();
        $otherUser = User::factory()->create();
        $otherDeck = $this->deckFor($otherUser);

        $response = $this->getJson("/api/decks/{$otherDeck->id}/cards");

        $response->assertNotFound();
    }

    public function test_it_returns_not_found_for_a_missing_deck(): void
    {
        $this->signIn();
        $missingDeckId = (string) Str::ulid();

        $response = $this->getJson("/api/decks/{$missingDeckId}/cards");

        $response->assertNotFound();
    }

    public function test_it_returns_not_found_for_a_malformed_deck_id(): void
    {
        $this->signIn();

        $response = $this->getJson('/api/decks/not-a-ulid/cards');

        $response->assertNotFound();
    }

    public function test_it_requires_authentication(): void
    {
        $missingDeckId = (string) Str::ulid();

        $response = $this->getJson("/api/decks/{$missingDeckId}/cards");

        $response->assertUnauthorized();
    }
}
