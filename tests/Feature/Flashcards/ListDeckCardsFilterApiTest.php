<?php

namespace Tests\Feature\Flashcards;

use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Flashcards\Enums\CardType;
use App\Domain\Flashcards\Models\Card;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SetsCardStudyStatus;
use Tests\TestCase;

class ListDeckCardsFilterApiTest extends TestCase
{
    use RefreshDatabase;
    use SetsCardStudyStatus;

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
        $this->assertInvalidFilter('study_status=%20%20%20', 'study_status', withoutTrimMiddleware: true);
    }

    public function test_it_rejects_a_malformed_deck_card_study_status_filter(): void
    {
        $this->assertInvalidFilter('study_status=queued', 'study_status');
    }

    public function test_it_rejects_an_array_deck_card_study_status_filter(): void
    {
        $this->assertInvalidFilter('study_status[]=review', 'study_status');
    }

    public function test_it_filters_deck_cards_by_card_type(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $otherDeck = $this->deckFor($user);
        $productionCard = Card::factory()->for($deck)->create([
            'card_type' => CardType::Production,
        ]);
        $recognitionCard = Card::factory()->for($deck)->create([
            'card_type' => CardType::Recognition,
        ]);
        $otherDeckCard = Card::factory()->for($otherDeck)->create([
            'card_type' => CardType::Production,
        ]);

        $response = $this->getJson("/api/decks/{$deck->id}/cards?card_type=production");

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $productionCard->id)
            ->assertJsonPath('data.0.card_type', 'production')
            ->assertJsonMissing(['id' => $recognitionCard->id])
            ->assertJsonMissing(['id' => $otherDeckCard->id]);
    }

    public function test_it_normalizes_deck_card_card_type_filters_without_global_trim_middleware(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $productionCard = Card::factory()->for($deck)->create([
            'card_type' => CardType::Production,
        ]);
        $recognitionCard = Card::factory()->for($deck)->create([
            'card_type' => CardType::Recognition,
        ]);

        $response = $this
            ->withoutMiddleware(TrimStrings::class)
            ->getJson("/api/decks/{$deck->id}/cards?card_type=%20PRODUCTION%20");

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $productionCard->id)
            ->assertJsonMissing(['id' => $recognitionCard->id]);
    }

    public function test_it_rejects_a_blank_deck_card_card_type_filter_without_global_trim_middleware(): void
    {
        $this->assertInvalidFilter('card_type=%20%20%20', 'card_type', withoutTrimMiddleware: true);
    }

    public function test_it_rejects_a_malformed_deck_card_card_type_filter(): void
    {
        $this->assertInvalidFilter('card_type=reverse', 'card_type');
    }

    public function test_it_rejects_an_array_deck_card_card_type_filter(): void
    {
        $this->assertInvalidFilter('card_type[]=production', 'card_type');
    }

    private function assertInvalidFilter(string $query, string $field, bool $withoutTrimMiddleware = false): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $test = $withoutTrimMiddleware ? $this->withoutMiddleware(TrimStrings::class) : $this;

        $test->getJson("/api/decks/{$deck->id}/cards?{$query}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field]);
    }
}
