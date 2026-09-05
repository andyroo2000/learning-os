<?php

namespace Tests\Feature\Flashcards;

use App\Domain\Flashcards\Actions\UpdateCardAction;
use App\Domain\Flashcards\Data\UpdateCardData;
use App\Domain\Flashcards\Enums\CardType;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Sync\Actions\RecordSyncFeedEntryAction;
use App\Domain\Sync\Data\RecordSyncFeedEntryData;
use App\Domain\Sync\Models\SyncFeedEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class UpdateCardSyncActionTest extends TestCase
{
    use RefreshDatabase;

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
        $this->assertNotNull($card->updated_at);
        $originalUpdatedAt = $card->updated_at->toJSON();

        $result = app(UpdateCardAction::class)->handle(
            $card,
            UpdateCardData::fromInput(
                frontText: '  ciao  ',
                backText: '  hello  ',
            ),
        );

        $this->assertFalse($result->wasUpdated);
        $this->assertSame($card->id, $result->card->id);

        $result->card->refresh();

        $this->assertNotNull($result->card->updated_at);
        $this->assertSame($originalUpdatedAt, $result->card->updated_at->toJSON());
        $this->assertDatabaseCount('sync_feed_entries', 0);
    }

    public function test_it_does_not_emit_a_sync_entry_only_to_fill_legacy_null_search_text(): void
    {
        $card = $this->cardFor($this->signIn(), [
            'front_text' => 'ciao',
            'back_text' => 'hello',
        ]);
        Card::query()
            ->whereKey($card->id)
            ->update(['search_text' => null]);
        $card->refresh();

        $result = app(UpdateCardAction::class)->handle(
            $card,
            UpdateCardData::fromInput(
                frontText: 'ciao',
                backText: 'hello',
            ),
        );

        $this->assertFalse($result->wasUpdated);
        $this->assertNull($result->card->search_text);
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
}
