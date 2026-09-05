<?php

namespace Tests\Feature\Flashcards;

use App\Domain\Flashcards\Enums\CardType;
use App\Domain\Flashcards\Models\Card;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            'card_type' => CardType::Production,
            'prompt_json' => ['type' => 'text', 'text' => 'ciao'],
            'answer_json' => ['type' => 'text', 'text' => 'hello'],
            'search_text' => 'ciao hello text ciao text hello',
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);
        $secondCard = Card::factory()->for($deck)->create([
            'front_text' => 'grazie',
            'back_text' => 'thanks',
            'card_type' => CardType::Cloze,
            'prompt_json' => ['type' => 'text', 'text' => 'grazie'],
            'answer_json' => ['type' => 'text', 'text' => 'thanks'],
            'search_text' => 'grazie thanks text grazie text thanks',
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
                        'search_text',
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
                'search_text' => 'ciao hello text ciao text hello',
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
                'search_text' => 'grazie thanks text grazie text thanks',
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
}
