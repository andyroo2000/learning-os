<?php

namespace Tests\Feature\Flashcards;

use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Flashcards\Enums\CardType;
use App\Domain\Flashcards\Models\Card;
use App\Support\Pagination\CursorPagination;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AssertsCursorPagination;
use Tests\Support\SetsCardStudyStatus;
use Tests\TestCase;

class ListDeckCardsPaginationApiTest extends TestCase
{
    use AssertsCursorPagination;
    use RefreshDatabase;
    use SetsCardStudyStatus;

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
            ->assertJsonCount(CursorPagination::MAX_PAGE_SIZE, 'data')
            ->assertJsonPath('data.0.front_text', 'Newer Card 1')
            ->assertJsonPath('data.'.(CursorPagination::MAX_PAGE_SIZE - 1).'.id', $highTieCard->id)
            ->assertJsonPath('meta.per_page', CursorPagination::MAX_PAGE_SIZE);

        $nextCursor = $firstPage->json('meta.next_cursor');

        $this->assertNotNull($nextCursor);

        $secondPage = $this->getJson("/api/decks/{$deck->id}/cards?cursor={$nextCursor}");

        $secondPage
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $lowTieCard->id)
            ->assertJsonPath('meta.next_cursor', null);
    }

    public function test_it_preserves_deck_card_study_status_filter_when_following_a_cursor(): void
    {
        $this->assertPaginationFilterPersists($this->studyStatusPaginationFixture());
    }

    public function test_it_preserves_deck_card_card_type_filter_when_following_a_cursor(): void
    {
        $this->assertPaginationFilterPersists($this->cardTypePaginationFixture());
    }

    public function test_it_preserves_deck_card_search_query_when_following_a_cursor(): void
    {
        $this->assertPaginationFilterPersists($this->searchPaginationFixture());
    }

    public function test_it_accepts_a_custom_page_size(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);

        Card::factory()->count(3)->for($deck)->create();

        $this->assertCursorEndpointAcceptsCustomPageSize("/api/decks/{$deck->id}/cards");
    }

    public function test_it_uses_the_default_page_size_when_omitted(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);

        Card::factory()->count(CursorPagination::DEFAULT_PAGE_SIZE + 1)->for($deck)->create();

        $this->assertCursorEndpointUsesDefaultPageSize("/api/decks/{$deck->id}/cards");
    }

    public function test_it_accepts_the_minimum_page_size(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);

        Card::factory()->count(3)->for($deck)->create();

        $this->assertCursorEndpointAcceptsMinimumPageSize("/api/decks/{$deck->id}/cards");
    }

    public function test_it_accepts_the_maximum_page_size(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);

        Card::factory()->count(CursorPagination::MAX_PAGE_SIZE + 1)->for($deck)->create();

        $this->assertCursorEndpointAcceptsMaximumPageSize("/api/decks/{$deck->id}/cards");
    }

    public function test_it_rejects_a_page_size_above_the_maximum(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);

        $this->assertCursorEndpointRejectsPageSize("/api/decks/{$deck->id}/cards", CursorPagination::MAX_PAGE_SIZE + 1);
    }

    public function test_it_rejects_a_page_size_below_the_minimum(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);

        $this->assertCursorEndpointRejectsPageSize("/api/decks/{$deck->id}/cards", 0);
    }

    public function test_it_rejects_a_negative_page_size(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);

        $this->assertCursorEndpointRejectsPageSize("/api/decks/{$deck->id}/cards", -1);
    }

    public function test_it_rejects_a_non_numeric_page_size(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);

        $this->assertCursorEndpointRejectsPageSize("/api/decks/{$deck->id}/cards", 'abc');
    }

    public function test_it_rejects_an_array_page_size(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);

        $this->assertCursorEndpointRejectsArrayPageSize("/api/decks/{$deck->id}/cards");
    }

    public function test_it_rejects_a_blank_page_size_without_global_trim_middleware(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);

        $this->assertCursorEndpointRejectsBlankPageSizeWithoutTrimMiddleware("/api/decks/{$deck->id}/cards");
    }

    public function test_it_rejects_invalid_cursor_values(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $uri = "/api/decks/{$deck->id}/cards";

        $this->assertCursorEndpointRejectsMalformedCursor($uri);
        $this->assertCursorEndpointRejectsArrayCursor($uri);
        $this->assertCursorEndpointRejectsParameterlessCursor($uri);
    }

    /**
     * @return array{deck_id: string, query: string, key: string, value: string, first_id: string, second_id: string, excluded_id: string}
     */
    private function studyStatusPaginationFixture(): array
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $firstReviewCard = $this->cardWithStudyStatus($deck, CardStudyStatus::Review, [
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $secondReviewCard = $this->cardWithStudyStatus($deck, CardStudyStatus::Review, [
            'created_at' => now()->subMinute(),
            'updated_at' => now()->subMinute(),
        ]);
        $newCard = $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'created_at' => now()->subSeconds(30),
            'updated_at' => now()->subSeconds(30),
        ]);

        return [
            'deck_id' => $deck->id,
            'query' => 'study_status=review',
            'key' => 'study_status',
            'value' => 'review',
            'first_id' => $firstReviewCard->id,
            'second_id' => $secondReviewCard->id,
            'excluded_id' => $newCard->id,
        ];
    }

    /**
     * @return array{deck_id: string, query: string, key: string, value: string, first_id: string, second_id: string, excluded_id: string}
     */
    private function cardTypePaginationFixture(): array
    {
        return $this->cardAttributePaginationFixture([
            'key' => 'card_type',
            'value' => 'production',
            'first' => ['card_type' => CardType::Production],
            'second' => ['card_type' => CardType::Production],
            'excluded' => ['card_type' => CardType::Recognition],
        ]);
    }

    /**
     * @return array{deck_id: string, query: string, key: string, value: string, first_id: string, second_id: string, excluded_id: string}
     */
    private function searchPaginationFixture(): array
    {
        return $this->cardAttributePaginationFixture([
            'key' => 'q',
            'value' => 'photo',
            'first' => ['search_text' => 'Photosynthesis alpha'],
            'second' => ['search_text' => 'Photosynthesis beta'],
            'excluded' => ['search_text' => 'Respiration'],
        ]);
    }

    /**
     * @param  array{key: string, value: string, first: array<string, mixed>, second: array<string, mixed>, excluded: array<string, mixed>}  $filter
     * @return array{deck_id: string, query: string, key: string, value: string, first_id: string, second_id: string, excluded_id: string}
     */
    private function cardAttributePaginationFixture(array $filter): array
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $firstCard = Card::factory()->for($deck)->create($filter['first'] + [
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $secondCard = Card::factory()->for($deck)->create($filter['second'] + [
            'created_at' => now()->subMinute(),
            'updated_at' => now()->subMinute(),
        ]);
        $excludedCard = Card::factory()->for($deck)->create($filter['excluded'] + [
            'created_at' => now()->subSeconds(30),
            'updated_at' => now()->subSeconds(30),
        ]);

        return [
            'deck_id' => $deck->id,
            'query' => "{$filter['key']}={$filter['value']}",
            'key' => $filter['key'],
            'value' => $filter['value'],
            'first_id' => $firstCard->id,
            'second_id' => $secondCard->id,
            'excluded_id' => $excludedCard->id,
        ];
    }

    /**
     * @param  array{deck_id: string, query: string, key: string, value: string, first_id: string, second_id: string, excluded_id: string}  $fixture
     */
    private function assertPaginationFilterPersists(array $fixture): void
    {
        $firstPage = $this->getJson("/api/decks/{$fixture['deck_id']}/cards?{$fixture['query']}&per_page=1");
        $firstPage->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $fixture['first_id']);
        $nextUrl = $firstPage->json('links.next');
        $this->assertNotNull($nextUrl);
        $this->assertUrlQueryParameter($nextUrl, $fixture['key'], $fixture['value']);
        $this->assertUrlQueryParameter($nextUrl, 'per_page', '1');
        $this->getJson($this->pathAndQueryFromUrl($nextUrl))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $fixture['second_id'])
            ->assertJsonMissing(['id' => $fixture['excluded_id']]);
    }
}
