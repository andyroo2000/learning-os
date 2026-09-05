<?php

namespace Tests\Feature\Flashcards;

use App\Domain\Courses\Models\Course;
use App\Domain\Flashcards\Actions\CreateCardAction;
use App\Domain\Flashcards\Data\CreateCardData;
use App\Domain\Flashcards\Models\Deck;
use App\Domain\Sync\Enums\SyncFeedOperation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Support\AssertsCardSyncFeedEntries;
use Tests\TestCase;

class CreateCardActionTest extends TestCase
{
    use AssertsCardSyncFeedEntries;
    use RefreshDatabase;

    public function test_it_creates_a_card_for_a_deck(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-04T12:00:00Z'));

        try {
            $course = Course::factory()->create();
            $deck = Deck::factory()->create([
                'user_id' => $course->user_id,
                'course_id' => $course->id,
            ]);

            $result = app(CreateCardAction::class)->handle(
                CreateCardData::fromInput(
                    userId: $deck->user_id,
                    deckId: $deck->id,
                    frontText: 'ciao',
                    backText: 'hello',
                ),
            );

            $card = $result->card->refresh();

            $this->assertTrue($result->wasCreated);
            $this->assertTrue(Str::isUlid($card->id));

            $this->assertDatabaseHas('cards', [
                'id' => $card->id,
                'deck_id' => $deck->id,
                'front_text' => 'ciao',
                'back_text' => 'hello',
                'new_queue_position' => 1,
            ]);

            $this->assertDatabaseCount('sync_feed_entries', 1);

            $entry = $this->assertCardSyncPayloadRecorded($card, SyncFeedOperation::Create);

            $this->assertSame($course->id, $entry->payload['course_id']);
            $this->assertSame('ciao', $entry->payload['front_text']);
            $this->assertSame('hello', $entry->payload['back_text']);
            $this->assertSame(1, $entry->payload['new_queue_position']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_it_creates_a_card_with_structured_content(): void
    {
        $deck = Deck::factory()->create();

        $result = app(CreateCardAction::class)->handle(
            CreateCardData::fromInput(
                userId: $deck->user_id,
                deckId: $deck->id,
                frontText: 'What is ATP?',
                backText: 'Cellular energy currency.',
                promptJson: ['type' => 'text', 'text' => 'What is ATP?'],
                answerJson: ['type' => 'text', 'text' => 'Cellular energy currency.'],
            ),
        );

        $this->assertSame(['type' => 'text', 'text' => 'What is ATP?'], $result->card->prompt_json);
        $this->assertSame(['type' => 'text', 'text' => 'Cellular energy currency.'], $result->card->answer_json);
        $this->assertSame(
            'What is ATP? Cellular energy currency. text What is ATP? text Cellular energy currency.',
            $result->card->search_text,
        );
        $this->assertDatabaseHas('cards', [
            'id' => $result->card->id,
            'prompt_json' => json_encode(['type' => 'text', 'text' => 'What is ATP?']),
            'answer_json' => json_encode(['type' => 'text', 'text' => 'Cellular energy currency.']),
            'search_text' => 'What is ATP? Cellular energy currency. text What is ATP? text Cellular energy currency.',
        ]);

        $this->assertDatabaseCount('sync_feed_entries', 1);

        $entry = $this->assertCardSyncPayloadRecorded($result->card->refresh(), SyncFeedOperation::Create);

        $this->assertSame(['type' => 'text', 'text' => 'What is ATP?'], $entry->payload['prompt_json']);
        $this->assertSame(['type' => 'text', 'text' => 'Cellular energy currency.'], $entry->payload['answer_json']);
    }

    public function test_it_appends_new_cards_to_the_users_new_card_queue(): void
    {
        $user = User::factory()->create();
        $deck = Deck::factory()->for($user)->create();
        $otherDeck = Deck::factory()->for($user)->create();
        $otherUserDeck = Deck::factory()->create();

        $first = app(CreateCardAction::class)->handle(
            CreateCardData::fromInput(
                userId: $user->id,
                deckId: $deck->id,
                frontText: 'ciao',
                backText: 'hello',
            ),
        )->card;
        $second = app(CreateCardAction::class)->handle(
            CreateCardData::fromInput(
                userId: $user->id,
                deckId: $otherDeck->id,
                frontText: 'arrivederci',
                backText: 'goodbye',
            ),
        )->card;
        $otherUserCard = app(CreateCardAction::class)->handle(
            CreateCardData::fromInput(
                userId: $otherUserDeck->user_id,
                deckId: $otherUserDeck->id,
                frontText: 'hola',
                backText: 'hello',
            ),
        )->card;

        $this->assertSame(1, $first->new_queue_position);
        $this->assertSame(2, $second->new_queue_position);
        $this->assertSame(1, $otherUserCard->new_queue_position);

        $this->assertDatabaseHas('cards', [
            'id' => $first->id,
            'new_queue_position' => 1,
        ]);
        $this->assertDatabaseHas('cards', [
            'id' => $second->id,
            'new_queue_position' => 2,
        ]);
        $this->assertDatabaseHas('cards', [
            'id' => $otherUserCard->id,
            'new_queue_position' => 1,
        ]);
    }

    public function test_it_uses_a_provided_ulid(): void
    {
        $deck = Deck::factory()->create();
        $id = (string) Str::ulid();

        $result = app(CreateCardAction::class)->handle(
            CreateCardData::fromInput(
                userId: $deck->user_id,
                deckId: $deck->id,
                frontText: 'ciao',
                backText: 'hello',
                id: strtoupper($id),
            ),
        );

        $card = $result->card;

        $this->assertTrue($result->wasCreated);
        $this->assertSame(strtolower($id), $card->id);

        $this->assertDatabaseHas('cards', [
            'id' => strtolower($id),
            'deck_id' => $deck->id,
        ]);
        $this->assertDatabaseHas('sync_feed_entries', [
            'user_id' => $deck->user_id,
            'domain' => 'flashcards',
            'resource_type' => 'card',
            'resource_id' => strtolower($id),
            'operation' => 'create',
        ]);
    }

    public function test_it_trims_text_inputs(): void
    {
        $deck = Deck::factory()->create();

        $result = app(CreateCardAction::class)->handle(
            CreateCardData::fromInput(
                userId: $deck->user_id,
                deckId: "  {$deck->id}  ",
                frontText: '  ciao  ',
                backText: '  hello  ',
            ),
        );

        $card = $result->card;

        $this->assertTrue($result->wasCreated);
        $this->assertSame($deck->id, $card->deck_id);
        $this->assertSame('ciao', $card->front_text);
        $this->assertSame('hello', $card->back_text);
    }

    public function test_it_normalizes_uppercase_deck_ids(): void
    {
        $deck = Deck::factory()->create();

        $result = app(CreateCardAction::class)->handle(
            CreateCardData::fromInput(
                userId: $deck->user_id,
                deckId: strtoupper($deck->id),
                frontText: 'ciao',
                backText: 'hello',
            ),
        );

        $card = $result->card;

        $this->assertTrue($result->wasCreated);
        $this->assertSame($deck->id, $card->deck_id);
    }
}
