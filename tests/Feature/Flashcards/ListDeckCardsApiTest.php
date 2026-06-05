<?php

namespace Tests\Feature\Flashcards;

use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Flashcards\Enums\CardType;
use App\Domain\Flashcards\Models\Card;
use App\Models\User;
use App\Support\Pagination\CursorPagination;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\AssertsCursorPagination;
use Tests\Support\SetsCardStudyStatus;
use Tests\TestCase;

class ListDeckCardsApiTest extends TestCase
{
    use AssertsCursorPagination;
    use RefreshDatabase;
    use SetsCardStudyStatus;

    public function test_it_lists_cards_for_an_owned_deck(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $otherDeck = $this->deckFor($user);

        $firstCard = Card::factory()->for($deck)->create([
            'front_text' => 'ciao',
            'back_text' => 'hello',
            'card_type' => CardType::Production,
            'prompt_json' => ['type' => 'text', 'text' => 'ciao'],
            'answer_json' => ['type' => 'text', 'text' => 'hello'],
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);
        $secondCard = Card::factory()->for($deck)->create([
            'front_text' => 'grazie',
            'back_text' => 'thanks',
            'card_type' => CardType::Cloze,
            'prompt_json' => ['type' => 'text', 'text' => 'grazie'],
            'answer_json' => ['type' => 'text', 'text' => 'thanks'],
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
                        'course_id',
                        'front_text',
                        'back_text',
                        'card_type',
                        'prompt_json',
                        'answer_json',
                        'study_status',
                        'new_queue_position',
                        'scheduler_state',
                        'due_at',
                        'introduced_at',
                        'failed_at',
                        'last_reviewed_at',
                        'created_at',
                        'updated_at',
                        'deleted_at',
                    ],
                ],
                'links',
                'meta',
            ])
            ->assertJsonFragment([
                'id' => $firstCard->id,
                'deck_id' => $deck->id,
                'course_id' => null,
                'front_text' => 'ciao',
                'back_text' => 'hello',
                'card_type' => 'production',
                'prompt_json' => ['type' => 'text', 'text' => 'ciao'],
                'answer_json' => ['type' => 'text', 'text' => 'hello'],
                'study_status' => 'new',
                'new_queue_position' => $firstCard->new_queue_position,
                'scheduler_state' => null,
                'due_at' => null,
                'introduced_at' => null,
                'failed_at' => null,
                'last_reviewed_at' => null,
                'created_at' => $firstCard->created_at?->toJSON(),
                'updated_at' => $firstCard->updated_at?->toJSON(),
                'deleted_at' => null,
            ])
            ->assertJsonFragment([
                'id' => $secondCard->id,
                'deck_id' => $deck->id,
                'course_id' => null,
                'front_text' => 'grazie',
                'back_text' => 'thanks',
                'card_type' => 'cloze',
                'prompt_json' => ['type' => 'text', 'text' => 'grazie'],
                'answer_json' => ['type' => 'text', 'text' => 'thanks'],
                'study_status' => 'new',
                'new_queue_position' => $secondCard->new_queue_position,
                'scheduler_state' => null,
                'due_at' => null,
                'introduced_at' => null,
                'failed_at' => null,
                'last_reviewed_at' => null,
                'created_at' => $secondCard->created_at?->toJSON(),
                'updated_at' => $secondCard->updated_at?->toJSON(),
                'deleted_at' => null,
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

    public function test_it_excludes_soft_deleted_cards(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $visibleCard = Card::factory()->for($deck)->create();
        $deletedCard = Card::factory()->for($deck)->create();

        $deletedCard->delete();

        $response = $this->getJson("/api/decks/{$deck->id}/cards");

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $visibleCard->id)
            ->assertJsonMissing([
                'id' => $deletedCard->id,
            ]);
    }

    public function test_it_filters_deck_cards_by_study_status(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $reviewCard = $this->cardWithStudyStatus($deck, CardStudyStatus::Review);
        $newCard = $this->cardWithStudyStatus($deck, CardStudyStatus::New);
        $otherDeckCard = $this->cardWithStudyStatus($this->deckFor($user), CardStudyStatus::Review);

        $response = $this->getJson("/api/decks/{$deck->id}/cards?study_status=review");

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $reviewCard->id)
            ->assertJsonPath('data.0.study_status', 'review')
            ->assertJsonMissing([
                'id' => $newCard->id,
            ])
            ->assertJsonMissing([
                'id' => $otherDeckCard->id,
            ]);
    }

    public function test_it_normalizes_deck_card_study_status_filters_without_global_trim_middleware(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $reviewCard = $this->cardWithStudyStatus($deck, CardStudyStatus::Review);
        $newCard = $this->cardWithStudyStatus($deck, CardStudyStatus::New);

        $response = $this
            ->withoutMiddleware(TrimStrings::class)
            ->getJson("/api/decks/{$deck->id}/cards?study_status=%20REVIEW%20");

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $reviewCard->id)
            ->assertJsonMissing([
                'id' => $newCard->id,
            ]);
    }

    public function test_it_rejects_a_blank_deck_card_study_status_filter_without_global_trim_middleware(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);

        $response = $this
            ->withoutMiddleware(TrimStrings::class)
            ->getJson("/api/decks/{$deck->id}/cards?study_status=%20%20%20");

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['study_status']);
    }

    public function test_it_rejects_a_malformed_deck_card_study_status_filter(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);

        $response = $this->getJson("/api/decks/{$deck->id}/cards?study_status=queued");

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['study_status']);
    }

    public function test_it_rejects_an_array_deck_card_study_status_filter(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);

        $response = $this->getJson("/api/decks/{$deck->id}/cards?study_status[]=review");

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['study_status']);
    }

    public function test_it_returns_not_found_for_a_soft_deleted_deck(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);

        $deck->delete();

        $response = $this->getJson("/api/decks/{$deck->id}/cards");

        $response->assertNotFound();
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

        $firstPage = $this->getJson("/api/decks/{$deck->id}/cards?study_status=review&per_page=1");

        $firstPage
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $firstReviewCard->id);

        $nextUrl = $firstPage->json('links.next');

        $this->assertNotNull($nextUrl);
        $this->assertUrlQueryParameter($nextUrl, 'study_status', 'review');
        $this->assertUrlQueryParameter($nextUrl, 'per_page', '1');

        $secondPage = $this->getJson($nextUrl);

        $secondPage
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $secondReviewCard->id)
            ->assertJsonMissing([
                'id' => $newCard->id,
            ]);
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
