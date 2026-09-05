<?php

namespace Tests\Feature\Flashcards;

use App\Domain\Flashcards\Enums\CardType;
use App\Domain\Flashcards\Models\Card;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ListCardsApiTest extends TestCase
{
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
            'card_type' => CardType::Production,
            'prompt_json' => ['type' => 'text', 'text' => 'ciao'],
            'answer_json' => ['type' => 'text', 'text' => 'hello'],
            'search_text' => 'ciao hello text ciao text hello',
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);
        $secondCard = Card::factory()->for($secondDeck)->create([
            'front_text' => 'grazie',
            'back_text' => 'thanks',
            'card_type' => CardType::Cloze,
            'prompt_json' => ['type' => 'text', 'text' => 'grazie'],
            'answer_json' => ['type' => 'text', 'text' => 'thanks'],
            'search_text' => 'grazie thanks text grazie text thanks',
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
                'deck_id' => $firstDeck->id,
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
                'deck_id' => $secondDeck->id,
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
}
