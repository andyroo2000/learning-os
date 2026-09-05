<?php

namespace Tests\Feature\Flashcards;

use App\Domain\Flashcards\Actions\CreateCardAction;
use App\Domain\Flashcards\Data\CreateCardData;
use App\Domain\Flashcards\Enums\CardType;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Models\Deck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use ReflectionMethod;
use Tests\TestCase;

class CreateCardRetryActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_existing_card_for_idempotent_retries(): void
    {
        $deck = Deck::factory()->create();
        $id = strtolower((string) Str::ulid());

        $existingCard = Card::factory()->for($deck)->create([
            'id' => $id,
            'front_text' => 'ciao',
            'back_text' => 'hello',
        ]);

        $result = app(CreateCardAction::class)->handle(
            CreateCardData::fromInput(
                userId: $deck->user_id,
                deckId: $deck->id,
                frontText: '  ciao  ',
                backText: '  hello  ',
                id: strtoupper($id),
            ),
        );

        $card = $result->card;

        $this->assertTrue($existingCard->is($card));
        $this->assertFalse($result->wasCreated);
        $this->assertDatabaseCount('cards', 1);
        $this->assertDatabaseCount('sync_feed_entries', 0);
    }

    public function test_it_matches_legacy_untrimmed_text_for_idempotent_retries(): void
    {
        $deck = Deck::factory()->create();
        $id = strtolower((string) Str::ulid());

        $existingCard = Card::factory()->for($deck)->create([
            'id' => $id,
            'front_text' => '  ciao  ',
            'back_text' => '  hello  ',
        ]);

        $result = app(CreateCardAction::class)->handle(
            CreateCardData::fromInput(
                userId: $deck->user_id,
                deckId: $deck->id,
                frontText: 'ciao',
                backText: 'hello',
                id: $id,
            ),
        );

        $card = $result->card;

        $this->assertTrue($existingCard->is($card));
        $this->assertFalse($result->wasCreated);
        $this->assertDatabaseCount('cards', 1);
        $this->assertDatabaseCount('sync_feed_entries', 0);
    }

    public function test_it_matches_legacy_null_card_type_for_idempotent_retries(): void
    {
        $deck = Deck::factory()->create();
        $id = strtolower((string) Str::ulid());

        $existingCard = new Card;
        $existingCard->setRawAttributes([
            'id' => $id,
            'deck_id' => $deck->id,
            'front_text' => 'ciao',
            'back_text' => 'hello',
            'card_type' => null,
            'deleted_at' => null,
        ], sync: true);
        $existingCard->setRelation('deck', $deck);

        $resolveExistingCard = new ReflectionMethod(CreateCardAction::class, 'resolveExistingCard');
        $resolveExistingCard->setAccessible(true);

        $result = $resolveExistingCard->invoke(
            app(CreateCardAction::class),
            $existingCard,
            CreateCardData::fromInput(
                userId: $deck->user_id,
                deckId: $deck->id,
                frontText: 'ciao',
                backText: 'hello',
                cardType: CardType::Recognition,
                id: $id,
            ),
        );

        $this->assertSame($existingCard, $result);
        $this->assertDatabaseCount('cards', 0);
        $this->assertDatabaseCount('sync_feed_entries', 0);
    }

    public function test_it_matches_reordered_structured_content_for_idempotent_retries(): void
    {
        $deck = Deck::factory()->create();
        $id = strtolower((string) Str::ulid());

        $existingCard = Card::factory()->for($deck)->create([
            'id' => $id,
            'front_text' => 'What is ATP?',
            'back_text' => 'Cellular energy currency.',
            'prompt_json' => [
                'text' => 'What is ATP?',
                'type' => 'text',
            ],
            'answer_json' => [
                'text' => 'Cellular energy currency.',
                'type' => 'text',
            ],
        ]);

        $result = app(CreateCardAction::class)->handle(
            CreateCardData::fromInput(
                userId: $deck->user_id,
                deckId: $deck->id,
                frontText: 'What is ATP?',
                backText: 'Cellular energy currency.',
                promptJson: [
                    'type' => 'text',
                    'text' => 'What is ATP?',
                ],
                answerJson: [
                    'type' => 'text',
                    'text' => 'Cellular energy currency.',
                ],
                id: $id,
            ),
        );

        $this->assertTrue($existingCard->is($result->card));
        $this->assertFalse($result->wasCreated);
        $this->assertDatabaseCount('cards', 1);
        $this->assertDatabaseCount('sync_feed_entries', 0);
    }
}
