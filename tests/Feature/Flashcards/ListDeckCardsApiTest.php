<?php

namespace Tests\Feature\Flashcards;

use App\Domain\Flashcards\Models\Card;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ListDeckCardsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_lists_cards_for_an_owned_deck(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $otherDeck = $this->deckFor($user);

        $firstCard = Card::factory()->for($deck)->create([
            'front_text' => 'ciao',
            'back_text' => 'hello',
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);
        $secondCard = Card::factory()->for($deck)->create([
            'front_text' => 'grazie',
            'back_text' => 'thanks',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $otherCard = Card::factory()->for($otherDeck)->create([
            'front_text' => 'bonjour',
        ]);

        $response = $this->getJson("/api/decks/{$deck->id}/cards");

        $response
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $secondCard->id)
            ->assertJsonPath('data.1.id', $firstCard->id)
            ->assertJsonMissingPath('data.0.media_assets')
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'deck_id',
                        'front_text',
                        'back_text',
                        'created_at',
                        'updated_at',
                    ],
                ],
                'links',
                'meta',
            ])
            ->assertJsonFragment([
                'id' => $firstCard->id,
                'deck_id' => $deck->id,
                'front_text' => 'ciao',
                'back_text' => 'hello',
                'created_at' => $firstCard->created_at?->toJSON(),
                'updated_at' => $firstCard->updated_at?->toJSON(),
            ])
            ->assertJsonFragment([
                'id' => $secondCard->id,
                'deck_id' => $deck->id,
                'front_text' => 'grazie',
                'back_text' => 'thanks',
                'created_at' => $secondCard->created_at?->toJSON(),
                'updated_at' => $secondCard->updated_at?->toJSON(),
            ])
            ->assertJsonMissing([
                'id' => $otherCard->id,
            ]);
    }

    public function test_it_returns_an_empty_list_when_the_deck_has_no_cards(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $cardInAnotherDeck = $this->cardFor($user);

        $response = $this->getJson("/api/decks/{$deck->id}/cards");

        $response
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJson([
                'data' => [],
            ])
            ->assertJsonMissing([
                'id' => $cardInAnotherDeck->id,
            ]);
    }

    public function test_it_uses_cursor_pagination_with_a_stable_id_tiebreaker(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $sharedTimestamp = now()->subDays(2);

        foreach (range(1, 49) as $index) {
            Card::factory()->for($deck)->create([
                'front_text' => "Newer Card {$index}",
                'created_at' => now()->subMinutes($index),
                'updated_at' => now()->subMinutes($index),
            ]);
        }

        $lowTieCard = Card::factory()->for($deck)->create([
            'id' => '01jzk7k5g9e1k8z6w3b4n9y2pa',
            'front_text' => 'Boundary Low',
            'created_at' => $sharedTimestamp,
            'updated_at' => $sharedTimestamp,
        ]);
        $highTieCard = Card::factory()->for($deck)->create([
            'id' => '01jzk7k5g9e1k8z6w3b4n9y2pb',
            'front_text' => 'Boundary High',
            'created_at' => $sharedTimestamp,
            'updated_at' => $sharedTimestamp,
        ]);

        $firstPage = $this->getJson("/api/decks/{$deck->id}/cards");

        $firstPage
            ->assertOk()
            ->assertJsonCount(50, 'data')
            ->assertJsonPath('data.0.front_text', 'Newer Card 1')
            ->assertJsonPath('data.49.id', $highTieCard->id)
            ->assertJsonPath('meta.per_page', 50);

        $nextCursor = $firstPage->json('meta.next_cursor');

        $this->assertNotNull($nextCursor);

        $secondPage = $this->getJson("/api/decks/{$deck->id}/cards?cursor={$nextCursor}");

        $secondPage
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $lowTieCard->id)
            ->assertJsonPath('meta.next_cursor', null);
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
        $missingDeckId = strtolower((string) Str::ulid());

        $response = $this->getJson("/api/decks/{$missingDeckId}/cards");

        $response->assertNotFound();
    }

    public function test_it_requires_authentication(): void
    {
        $missingDeckId = strtolower((string) Str::ulid());

        $response = $this->getJson("/api/decks/{$missingDeckId}/cards");

        $response->assertUnauthorized();
    }
}
