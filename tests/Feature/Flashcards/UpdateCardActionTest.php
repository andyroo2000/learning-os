<?php

namespace Tests\Feature\Flashcards;

use App\Domain\Courses\Models\Course;
use App\Domain\Flashcards\Actions\UpdateCardAction;
use App\Domain\Flashcards\Data\UpdateCardData;
use App\Domain\Flashcards\Enums\CardType;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Models\Deck;
use App\Domain\Sync\Actions\RecordSyncFeedEntryAction;
use App\Domain\Sync\Data\RecordSyncFeedEntryData;
use App\Domain\Sync\Enums\SyncFeedOperation;
use App\Domain\Sync\Models\SyncFeedEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class UpdateCardActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_updates_card_text(): void
    {
        $user = $this->signIn();
        $course = Course::factory()->create(['user_id' => $user->id]);
        $deck = Deck::factory()->create([
            'user_id' => $user->id,
            'course_id' => $course->id,
        ]);
        $card = Card::factory()->for($deck)->create();

        $result = app(UpdateCardAction::class)->handle(
            $card,
            UpdateCardData::fromInput(
                frontText: 'arrivederci',
                backText: 'goodbye',
            ),
        );
        $updatedCard = $result->card;

        $this->assertTrue($result->wasUpdated);
        $this->assertSame($card->id, $updatedCard->id);

        $this->assertDatabaseHas('cards', [
            'id' => $card->id,
            'deck_id' => $card->deck_id,
            'front_text' => 'arrivederci',
            'back_text' => 'goodbye',
        ]);

        $entry = SyncFeedEntry::query()->sole();

        $this->assertSame($card->deck->user_id, $entry->user_id);
        $this->assertSame('flashcards', $entry->domain);
        $this->assertSame('card', $entry->resource_type);
        $this->assertSame($card->id, $entry->resource_id);
        $this->assertSame(SyncFeedOperation::Update, $entry->operation);
        $this->assertSame([
            'id' => $card->id,
            'deck_id' => $card->deck_id,
            'course_id' => $course->id,
            'front_text' => 'arrivederci',
            'back_text' => 'goodbye',
            'card_type' => 'recognition',
            'prompt_json' => null,
            'answer_json' => null,
            'study_status' => 'new',
            'new_queue_position' => $updatedCard->new_queue_position,
            'scheduler_state' => null,
            'due_at' => null,
            'introduced_at' => null,
            'failed_at' => null,
            'last_reviewed_at' => null,
            'created_at' => $updatedCard->created_at?->toJSON(),
            'updated_at' => $updatedCard->updated_at?->toJSON(),
            'deleted_at' => null,
        ], $entry->payload);
    }

    public function test_it_updates_card_type(): void
    {
        $card = $this->cardFor($this->signIn(), [
            'card_type' => CardType::Recognition,
            'front_text' => 'ciao',
            'back_text' => 'hello',
        ]);

        $result = app(UpdateCardAction::class)->handle(
            $card,
            UpdateCardData::fromInput(
                frontText: 'ciao',
                backText: 'hello',
                cardType: ' CLOZE ',
            ),
        );

        $this->assertTrue($result->wasUpdated);
        $this->assertSame(CardType::Cloze, $result->card->card_type);
        $this->assertDatabaseHas('cards', [
            'id' => $card->id,
            'card_type' => 'cloze',
        ]);
        $this->assertSame('cloze', SyncFeedEntry::query()->sole()->payload['card_type']);
    }

    public function test_it_updates_structured_content(): void
    {
        $card = $this->cardFor($this->signIn(), [
            'front_text' => 'What is ATP?',
            'back_text' => 'Cellular energy currency.',
        ]);

        $result = app(UpdateCardAction::class)->handle(
            $card,
            UpdateCardData::fromInput(
                frontText: 'What is ATP?',
                backText: 'Cellular energy currency.',
                hasPromptJson: true,
                promptJson: ['type' => 'text', 'text' => 'What is ATP?'],
                hasAnswerJson: true,
                answerJson: ['type' => 'text', 'text' => 'Cellular energy currency.'],
            ),
        );

        $this->assertTrue($result->wasUpdated);
        $this->assertSame(['type' => 'text', 'text' => 'What is ATP?'], $result->card->prompt_json);
        $this->assertSame(['type' => 'text', 'text' => 'Cellular energy currency.'], $result->card->answer_json);

        $payload = SyncFeedEntry::query()->sole()->payload;

        $this->assertSame(['type' => 'text', 'text' => 'What is ATP?'], $payload['prompt_json']);
        $this->assertSame(['type' => 'text', 'text' => 'Cellular energy currency.'], $payload['answer_json']);
    }

    public function test_it_clears_structured_content_when_explicit_nulls_are_provided(): void
    {
        $card = $this->cardFor($this->signIn(), [
            'front_text' => 'What is ATP?',
            'back_text' => 'Cellular energy currency.',
            'prompt_json' => ['type' => 'text', 'text' => 'What is ATP?'],
            'answer_json' => ['type' => 'text', 'text' => 'Cellular energy currency.'],
        ]);

        $result = app(UpdateCardAction::class)->handle(
            $card,
            UpdateCardData::fromInput(
                frontText: 'What is ATP?',
                backText: 'Cellular energy currency.',
                hasPromptJson: true,
                promptJson: null,
                hasAnswerJson: true,
                answerJson: null,
            ),
        );

        $this->assertTrue($result->wasUpdated);
        $this->assertNull($result->card->prompt_json);
        $this->assertNull($result->card->answer_json);
        $this->assertNull(SyncFeedEntry::query()->sole()->payload['prompt_json']);
        $this->assertNull(SyncFeedEntry::query()->sole()->payload['answer_json']);
    }

    public function test_it_trims_text_inputs(): void
    {
        $card = $this->cardFor($this->signIn(), [
            'front_text' => 'ciao',
            'back_text' => 'hello',
        ]);

        $result = app(UpdateCardAction::class)->handle(
            $card,
            UpdateCardData::fromInput(
                frontText: '  arrivederci  ',
                backText: '  goodbye  ',
            ),
        );
        $updatedCard = $result->card;

        $this->assertTrue($result->wasUpdated);
        $this->assertSame('arrivederci', $updatedCard->front_text);
        $this->assertSame('goodbye', $updatedCard->back_text);
    }

    public function test_it_rolls_back_when_feed_recording_fails(): void
    {
        $card = $this->cardFor($this->signIn(), [
            'front_text' => 'ciao',
            'back_text' => 'hello',
        ]);
        $updateCard = new UpdateCardAction(
            recordSyncFeedEntry: new class extends RecordSyncFeedEntryAction
            {
                public function handle(RecordSyncFeedEntryData $data): SyncFeedEntry
                {
                    throw new RuntimeException('Sync feed failed.');
                }
            },
        );

        try {
            $updateCard->handle(
                $card,
                UpdateCardData::fromInput(
                    frontText: 'arrivederci',
                    backText: 'goodbye',
                ),
            );

            $this->fail('Expected sync feed failure was not thrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Sync feed failed.', $exception->getMessage());
            $this->assertDatabaseHas('cards', [
                'id' => $card->id,
                'front_text' => 'ciao',
                'back_text' => 'hello',
            ]);
            $this->assertDatabaseCount('sync_feed_entries', 0);
        }
    }

    public function test_it_marks_unchanged_when_normalized_text_matches_the_existing_card(): void
    {
        $card = $this->cardFor($this->signIn(), [
            'front_text' => 'ciao',
            'back_text' => 'hello',
        ]);

        $result = app(UpdateCardAction::class)->handle(
            $card,
            UpdateCardData::fromInput(
                frontText: '  ciao  ',
                backText: '  hello  ',
            ),
        );

        $this->assertFalse($result->wasUpdated);
        $this->assertSame($card->id, $result->card->id);
        $this->assertDatabaseCount('sync_feed_entries', 0);
    }

    public function test_it_marks_unchanged_when_card_type_is_omitted(): void
    {
        $card = $this->cardFor($this->signIn(), [
            'card_type' => CardType::Production,
            'front_text' => 'ciao',
            'back_text' => 'hello',
        ]);

        $result = app(UpdateCardAction::class)->handle(
            $card,
            UpdateCardData::fromInput(
                frontText: 'ciao',
                backText: 'hello',
            ),
        );

        $this->assertFalse($result->wasUpdated);
        $this->assertSame(CardType::Production, $result->card->card_type);
        $this->assertDatabaseCount('sync_feed_entries', 0);
    }

    public function test_it_marks_unchanged_when_structured_content_is_omitted(): void
    {
        $card = $this->cardFor($this->signIn(), [
            'front_text' => 'What is ATP?',
            'back_text' => 'Cellular energy currency.',
            'prompt_json' => ['type' => 'text', 'text' => 'What is ATP?'],
            'answer_json' => ['type' => 'text', 'text' => 'Cellular energy currency.'],
        ]);

        $result = app(UpdateCardAction::class)->handle(
            $card,
            UpdateCardData::fromInput(
                frontText: 'What is ATP?',
                backText: 'Cellular energy currency.',
            ),
        );

        $this->assertFalse($result->wasUpdated);
        $this->assertSame(['type' => 'text', 'text' => 'What is ATP?'], $result->card->prompt_json);
        $this->assertSame(['type' => 'text', 'text' => 'Cellular energy currency.'], $result->card->answer_json);
        $this->assertDatabaseCount('sync_feed_entries', 0);
    }

    public function test_it_rejects_blank_front_text(): void
    {
        $card = $this->cardFor($this->signIn());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Card front text is required.');

        app(UpdateCardAction::class)->handle(
            $card,
            UpdateCardData::fromInput(
                frontText: '   ',
                backText: 'goodbye',
            ),
        );
    }

    public function test_it_rejects_blank_back_text(): void
    {
        $card = $this->cardFor($this->signIn());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Card back text is required.');

        app(UpdateCardAction::class)->handle(
            $card,
            UpdateCardData::fromInput(
                frontText: 'arrivederci',
                backText: '   ',
            ),
        );
    }

    public function test_it_rejects_blank_card_type_for_direct_callers(): void
    {
        $card = $this->cardFor($this->signIn());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Card type must not be blank when provided.');

        app(UpdateCardAction::class)->handle(
            $card,
            UpdateCardData::fromInput(
                frontText: 'arrivederci',
                backText: 'goodbye',
                cardType: '   ',
            ),
        );
    }

    public function test_it_rejects_malformed_card_type_for_direct_callers(): void
    {
        $card = $this->cardFor($this->signIn());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Card type must be one of: recognition, production, cloze.');

        app(UpdateCardAction::class)->handle(
            $card,
            UpdateCardData::fromInput(
                frontText: 'arrivederci',
                backText: 'goodbye',
                cardType: 'reverse',
            ),
        );
    }
}
