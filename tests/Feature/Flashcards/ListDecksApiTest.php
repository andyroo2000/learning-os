<?php

namespace Tests\Feature\Flashcards;

use App\Domain\Flashcards\Models\Deck;
use App\Models\User;
use App\Support\Pagination\CursorPagination;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListDecksApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_lists_decks_for_the_authenticated_user(): void
    {
        $user = $this->signIn();
        $otherUser = User::factory()->create();

        $firstDeck = Deck::factory()->for($user)->create([
            'name' => 'Italian Basics',
            'description' => 'Foundational Italian review cards.',
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);
        $secondDeck = Deck::factory()->for($user)->create([
            'name' => 'Travel Phrases',
            'description' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $otherDeck = Deck::factory()->for($otherUser)->create([
            'name' => 'Hidden Spanish Deck',
        ]);

        $response = $this->getJson('/api/decks');

        $response
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $secondDeck->id)
            ->assertJsonPath('data.1.id', $firstDeck->id)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'name',
                        'description',
                        'created_at',
                        'updated_at',
                    ],
                ],
                'links',
                'meta',
            ])
            ->assertJsonFragment([
                'id' => $firstDeck->id,
                'name' => 'Italian Basics',
                'description' => 'Foundational Italian review cards.',
                'created_at' => $firstDeck->created_at?->toJSON(),
                'updated_at' => $firstDeck->updated_at?->toJSON(),
            ])
            ->assertJsonFragment([
                'id' => $secondDeck->id,
                'name' => 'Travel Phrases',
                'description' => null,
                'created_at' => $secondDeck->created_at?->toJSON(),
                'updated_at' => $secondDeck->updated_at?->toJSON(),
            ])
            ->assertJsonMissing([
                'id' => $otherDeck->id,
            ]);
    }

    public function test_it_returns_an_empty_list_when_the_user_has_no_decks(): void
    {
        $this->signIn();
        $this->deckFor(User::factory()->create());

        $response = $this->getJson('/api/decks');

        $response
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJson([
                'data' => [],
            ]);
    }

    public function test_it_excludes_soft_deleted_decks(): void
    {
        $user = $this->signIn();
        $visibleDeck = Deck::factory()->for($user)->create();
        $deletedDeck = Deck::factory()->for($user)->create();

        $deletedDeck->delete();

        $response = $this->getJson('/api/decks');

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $visibleDeck->id)
            ->assertJsonMissing([
                'id' => $deletedDeck->id,
            ]);
    }

    public function test_it_uses_cursor_pagination_with_a_stable_id_tiebreaker(): void
    {
        $user = $this->signIn();
        $sharedTimestamp = now()->subDays(2);

        foreach (range(1, 49) as $index) {
            Deck::factory()->for($user)->create([
                'name' => "Newer Deck {$index}",
                'created_at' => now()->subMinutes($index),
                'updated_at' => now()->subMinutes($index),
            ]);
        }

        $lowTieDeck = Deck::factory()->for($user)->create([
            'name' => 'Boundary Low',
            'created_at' => $sharedTimestamp,
            'updated_at' => $sharedTimestamp,
        ]);
        $highTieDeck = Deck::factory()->for($user)->create([
            'name' => 'Boundary High',
            'created_at' => $sharedTimestamp,
            'updated_at' => $sharedTimestamp,
        ]);

        $firstPage = $this->getJson('/api/decks');

        $firstPage
            ->assertOk()
            ->assertJsonCount(50, 'data')
            ->assertJsonPath('data.0.name', 'Newer Deck 1')
            ->assertJsonPath('data.49.id', $highTieDeck->id)
            ->assertJsonPath('meta.per_page', 50);

        $nextCursor = $firstPage->json('meta.next_cursor');

        $this->assertNotNull($nextCursor);

        $secondPage = $this->getJson("/api/decks?cursor={$nextCursor}");

        $secondPage
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $lowTieDeck->id)
            ->assertJsonPath('meta.next_cursor', null);
    }

    public function test_it_accepts_a_custom_page_size(): void
    {
        $user = $this->signIn();

        Deck::factory()->count(3)->for($user)->create();

        $response = $this->getJson('/api/decks?per_page=2');

        $response
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.per_page', 2);

        $nextUrl = $response->json('links.next');

        $this->assertNotNull($nextUrl);
        $this->assertUrlQueryParameter($nextUrl, 'per_page', '2');

        $this->getJson($nextUrl)
            ->assertOk()
            ->assertJsonPath('meta.per_page', 2);
    }

    public function test_it_uses_the_default_page_size_when_omitted(): void
    {
        $user = $this->signIn();

        Deck::factory()->count(CursorPagination::MAX_PAGE_SIZE + 1)->for($user)->create();

        $response = $this->getJson('/api/decks');

        $response
            ->assertOk()
            ->assertJsonCount(CursorPagination::MAX_PAGE_SIZE, 'data')
            ->assertJsonPath('meta.per_page', CursorPagination::MAX_PAGE_SIZE);
    }

    public function test_it_accepts_the_minimum_page_size(): void
    {
        $user = $this->signIn();

        Deck::factory()->count(3)->for($user)->create();

        $response = $this->getJson('/api/decks?per_page=1');

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.per_page', 1);
    }

    public function test_it_accepts_the_maximum_page_size(): void
    {
        $user = $this->signIn();

        Deck::factory()->count(CursorPagination::MAX_PAGE_SIZE + 1)->for($user)->create();

        $response = $this->getJson('/api/decks?per_page='.CursorPagination::MAX_PAGE_SIZE);

        $response
            ->assertOk()
            ->assertJsonCount(CursorPagination::MAX_PAGE_SIZE, 'data')
            ->assertJsonPath('meta.per_page', CursorPagination::MAX_PAGE_SIZE);
    }

    public function test_it_rejects_a_page_size_above_the_maximum(): void
    {
        $this->signIn();

        $response = $this->getJson('/api/decks?per_page='.(CursorPagination::MAX_PAGE_SIZE + 1));

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors('per_page');
    }

    public function test_it_rejects_a_page_size_below_the_minimum(): void
    {
        $this->signIn();

        $response = $this->getJson('/api/decks?per_page=0');

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors('per_page');
    }

    public function test_it_rejects_a_non_numeric_page_size(): void
    {
        $this->signIn();

        $response = $this->getJson('/api/decks?per_page=abc');

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors('per_page');
    }

    public function test_it_requires_authentication(): void
    {
        Deck::factory()->create();

        $response = $this->getJson('/api/decks');

        $response->assertUnauthorized();
    }
}
