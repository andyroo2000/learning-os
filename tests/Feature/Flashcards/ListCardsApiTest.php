<?php

namespace Tests\Feature\Flashcards;

use App\Domain\Flashcards\Models\Card;
use App\Models\User;
use App\Support\Pagination\CursorPagination;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\AssertsCursorPagination;
use Tests\TestCase;

class ListCardsApiTest extends TestCase
{
    use AssertsCursorPagination;
    use RefreshDatabase;

    public function test_it_lists_cards_for_the_authenticated_user_across_decks(): void
    {
        $user = $this->signIn();
        $firstDeck = $this->deckFor($user);
        $secondDeck = $this->deckFor($user);
        $otherUser = User::factory()->create();

        $firstCard = Card::factory()->for($firstDeck)->create([
            'front_text' => 'ciao',
            'back_text' => 'hello',
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);
        $secondCard = Card::factory()->for($secondDeck)->create([
            'front_text' => 'grazie',
            'back_text' => 'thanks',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $otherCard = $this->cardFor($otherUser, [
            'front_text' => 'bonjour',
        ]);

        $response = $this->getJson('/api/cards');

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
                'deck_id' => $firstDeck->id,
                'front_text' => 'ciao',
                'back_text' => 'hello',
                'created_at' => $firstCard->created_at?->toJSON(),
                'updated_at' => $firstCard->updated_at?->toJSON(),
            ])
            ->assertJsonFragment([
                'id' => $secondCard->id,
                'deck_id' => $secondDeck->id,
                'front_text' => 'grazie',
                'back_text' => 'thanks',
                'created_at' => $secondCard->created_at?->toJSON(),
                'updated_at' => $secondCard->updated_at?->toJSON(),
            ])
            ->assertJsonMissing([
                'id' => $otherCard->id,
            ]);
    }

    public function test_it_returns_an_empty_list_when_the_user_has_no_cards(): void
    {
        $this->signIn();
        $otherUser = User::factory()->create();
        $otherCard = $this->cardFor($otherUser);

        $response = $this->getJson('/api/cards');

        $response
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJson([
                'data' => [],
            ])
            ->assertJsonMissing([
                'id' => $otherCard->id,
            ]);
    }

    public function test_it_excludes_soft_deleted_cards(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $visibleCard = Card::factory()->for($deck)->create();
        $deletedCard = Card::factory()->for($deck)->create();

        $deletedCard->delete();

        $response = $this->getJson('/api/cards');

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $visibleCard->id)
            ->assertJsonMissing([
                'id' => $deletedCard->id,
            ]);
    }

    public function test_it_excludes_cards_from_soft_deleted_decks(): void
    {
        $user = $this->signIn();
        $visibleCard = $this->cardFor($user);
        $deletedDeck = $this->deckFor($user);
        $deletedDeckCard = Card::factory()->for($deletedDeck)->create();

        $deletedDeck->delete();

        $response = $this->getJson('/api/cards');

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $visibleCard->id)
            ->assertJsonMissing([
                'id' => $deletedDeckCard->id,
            ]);
    }

    public function test_it_does_not_query_media_relationships_for_the_list_response(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);

        Card::factory()->count(3)->for($deck)->create();

        $relationshipQueries = [];

        DB::listen(function ($query) use (&$relationshipQueries): void {
            if (str_contains($query->sql, 'card_media') || str_contains($query->sql, 'media_assets')) {
                $relationshipQueries[] = $query->sql;
            }
        });

        $response = $this->getJson('/api/cards');

        $response->assertOk();

        $this->assertSame([], $relationshipQueries);
    }

    public function test_it_uses_cursor_pagination_with_a_stable_id_tiebreaker(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $sharedTimestamp = now()->subDays(2);

        foreach (range(1, CursorPagination::MAX_PAGE_SIZE - 1) as $index) {
            Card::factory()->for($deck)->create([
                'front_text' => "Newer Card {$index}",
                'created_at' => now()->subMinutes($index),
                'updated_at' => now()->subMinutes($index),
            ]);
        }

        $lowTieCard = Card::factory()->for($deck)->create([
            'id' => '01jzk7k5g9e1k8z6w3b4n9y2pg',
            'created_at' => $sharedTimestamp,
            'updated_at' => $sharedTimestamp,
        ]);
        $highTieCard = Card::factory()->for($deck)->create([
            'id' => '01jzk7k5g9e1k8z6w3b4n9y2ph',
            'created_at' => $sharedTimestamp,
            'updated_at' => $sharedTimestamp,
        ]);

        $firstPage = $this->getJson('/api/cards');

        $firstPage
            ->assertOk()
            ->assertJsonCount(CursorPagination::MAX_PAGE_SIZE, 'data')
            ->assertJsonPath('data.0.front_text', 'Newer Card 1')
            ->assertJsonPath('data.'.(CursorPagination::MAX_PAGE_SIZE - 1).'.id', $highTieCard->id)
            ->assertJsonPath('meta.per_page', CursorPagination::MAX_PAGE_SIZE);

        $nextCursor = $firstPage->json('meta.next_cursor');

        $this->assertNotNull($nextCursor);

        $secondPage = $this->getJson("/api/cards?cursor={$nextCursor}");

        $secondPage
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $lowTieCard->id)
            ->assertJsonPath('meta.next_cursor', null);
    }

    public function test_it_accepts_a_custom_page_size(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);

        Card::factory()->count(3)->for($deck)->create();

        $this->assertCursorEndpointAcceptsCustomPageSize('/api/cards');
    }

    public function test_it_uses_the_default_page_size_when_omitted(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);

        Card::factory()->count(CursorPagination::DEFAULT_PAGE_SIZE + 1)->for($deck)->create();

        $this->assertCursorEndpointUsesDefaultPageSize('/api/cards');
    }

    public function test_it_accepts_the_minimum_page_size(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);

        Card::factory()->count(3)->for($deck)->create();

        $this->assertCursorEndpointAcceptsMinimumPageSize('/api/cards');
    }

    public function test_it_accepts_the_maximum_page_size(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);

        Card::factory()->count(CursorPagination::MAX_PAGE_SIZE + 1)->for($deck)->create();

        $this->assertCursorEndpointAcceptsMaximumPageSize('/api/cards');
    }

    public function test_it_rejects_a_page_size_above_the_maximum(): void
    {
        $this->signIn();

        $this->assertCursorEndpointRejectsPageSize('/api/cards', CursorPagination::MAX_PAGE_SIZE + 1);
    }

    public function test_it_rejects_a_page_size_below_the_minimum(): void
    {
        $this->signIn();

        $this->assertCursorEndpointRejectsPageSize('/api/cards', 0);
    }

    public function test_it_rejects_a_negative_page_size(): void
    {
        $this->signIn();

        $this->assertCursorEndpointRejectsPageSize('/api/cards', -1);
    }

    public function test_it_rejects_a_non_numeric_page_size(): void
    {
        $this->signIn();

        $this->assertCursorEndpointRejectsPageSize('/api/cards', 'abc');
    }

    public function test_it_requires_authentication(): void
    {
        $response = $this->getJson('/api/cards');

        $response->assertUnauthorized();
    }
}
