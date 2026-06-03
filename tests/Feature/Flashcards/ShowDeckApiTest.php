<?php

namespace Tests\Feature\Flashcards;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ShowDeckApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_shows_an_owned_deck(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user, [
            'name' => 'Italian Travel',
            'description' => 'Phrases for airport and train station practice.',
        ]);

        $response = $this->getJson("/api/decks/{$deck->id}");

        $response
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'id' => $deck->id,
                    'name' => 'Italian Travel',
                    'description' => 'Phrases for airport and train station practice.',
                    'created_at' => $deck->created_at->toJSON(),
                    'updated_at' => $deck->updated_at->toJSON(),
                    'deleted_at' => null,
                ],
            ]);
    }

    public function test_it_hides_another_users_deck(): void
    {
        $this->signIn();
        $otherUser = User::factory()->create();
        $otherDeck = $this->deckFor($otherUser);

        $response = $this->getJson("/api/decks/{$otherDeck->id}");

        $response->assertNotFound();
    }

    public function test_it_returns_not_found_for_a_missing_deck(): void
    {
        $this->signIn();

        $response = $this->getJson('/api/decks/'.((string) Str::ulid()));

        $response->assertNotFound();
    }

    public function test_it_returns_not_found_for_a_malformed_deck_id(): void
    {
        $this->signIn();

        $response = $this->getJson('/api/decks/not-a-ulid');

        $response->assertNotFound();
    }

    public function test_it_returns_not_found_for_a_soft_deleted_deck(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);

        $deck->delete();

        $response = $this->getJson("/api/decks/{$deck->id}");

        $response->assertNotFound();
    }

    public function test_it_requires_authentication(): void
    {
        $deck = $this->deckFor(User::factory()->create());

        $response = $this->getJson("/api/decks/{$deck->id}");

        $response->assertUnauthorized();
    }
}
