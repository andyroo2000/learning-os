<?php

namespace Tests\Feature\Flashcards;

use App\Domain\Courses\Models\Course;
use App\Domain\Flashcards\Actions\UpdateCardAction;
use App\Domain\Flashcards\Data\UpdateCardData;
use App\Domain\Flashcards\Enums\CardType;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Models\Deck;
use App\Domain\Flashcards\Sync\CardSyncPayload;
use App\Domain\Sync\Actions\RecordSyncFeedEntryAction;
use App\Domain\Sync\Data\RecordSyncFeedEntryData;
use App\Domain\Sync\Enums\SyncFeedOperation;
use App\Domain\Sync\Models\SyncFeedEntry;
use App\Domain\Vocabulary\Enums\VocabVariantKind;
use App\Domain\Vocabulary\Enums\VocabVariantStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
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
        $updatedCard = $result->card->refresh();

        $this->assertTrue($result->wasUpdated);
        $this->assertSame($card->id, $updatedCard->id);

        $this->assertDatabaseHas('cards', [
            'id' => $card->id,
            'deck_id' => $card->deck_id,
            'front_text' => 'arrivederci',
            'back_text' => 'goodbye',
        ]);

        $entry = SyncFeedEntry::query()->sole();

        $this->assertSame($updatedCard->deck->user_id, $entry->user_id);
        $this->assertSame(CardSyncPayload::DOMAIN, $entry->domain);
        $this->assertSame(CardSyncPayload::RESOURCE_TYPE, $entry->resource_type);
        $this->assertSame($updatedCard->id, $entry->resource_id);
        $this->assertSame(SyncFeedOperation::Update, $entry->operation);
        $this->assertEquals(CardSyncPayload::fromCard($updatedCard), $entry->payload);
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
        $this->assertEquals(CardSyncPayload::fromCard($result->card->refresh()), SyncFeedEntry::query()->sole()->payload);
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
        $this->assertSame(
            'What is ATP? Cellular energy currency. text What is ATP? text Cellular energy currency.',
            $result->card->search_text,
        );

        $this->assertEquals(CardSyncPayload::fromCard($result->card->refresh()), SyncFeedEntry::query()->sole()->payload);
    }

    public function test_it_updates_variant_metadata_for_direct_callers(): void
    {
        $card = $this->cardFor($this->signIn(), [
            'front_text' => '会社',
            'back_text' => 'company',
            'prompt_json' => ['cueText' => '会社'],
            'answer_json' => ['meaning' => 'company'],
        ]);

        $result = app(UpdateCardAction::class)->handle(
            $card,
            UpdateCardData::fromInput(
                frontText: '会社',
                backText: 'company',
                hasVariantGroupId: true,
                variantGroupId: ' vocab-group-1 ',
                hasVariantSentenceId: true,
                variantSentenceId: ' sentence-1 ',
                hasVariantKind: true,
                variantKind: ' SENTENCE_CLOZE ',
                hasVariantStage: true,
                variantStage: 3,
                hasVariantStatus: true,
                variantStatus: VocabVariantStatus::Available,
                hasVariantUnlockedAt: true,
                variantUnlockedAt: Carbon::parse('2026-06-04T14:15:30.987654+05:30'),
            ),
        );

        $this->assertTrue($result->wasUpdated);

        $card->refresh();
        $this->assertSame('vocab-group-1', $card->variant_group_id);
        $this->assertSame('sentence-1', $card->variant_sentence_id);
        $this->assertSame(VocabVariantKind::SentenceCloze->value, $card->variant_kind);
        $this->assertSame(3, $card->variant_stage);
        $this->assertSame(VocabVariantStatus::Available->value, $card->variant_status);
        $this->assertSame('2026-06-04T08:45:30.000000Z', $card->variant_unlocked_at?->toJSON());

        $entry = SyncFeedEntry::query()->sole();
        $this->assertEquals(CardSyncPayload::fromCard($card), $entry->payload);
    }

    public function test_it_clears_variant_metadata_for_direct_callers(): void
    {
        $card = $this->cardFor($this->signIn(), [
            'front_text' => '会社',
            'back_text' => 'company',
            'variant_group_id' => 'old-group',
            'variant_sentence_id' => 'old-sentence',
            'variant_kind' => VocabVariantKind::SentenceAudioRecognition,
            'variant_stage' => 2,
            'variant_status' => VocabVariantStatus::Locked,
            'variant_unlocked_at' => Carbon::parse('2026-06-05T14:15:00Z'),
        ]);
        $card->refresh();

        $result = app(UpdateCardAction::class)->handle(
            $card,
            UpdateCardData::fromInput(
                frontText: '会社',
                backText: 'company',
                hasVariantGroupId: true,
                variantGroupId: '   ',
                hasVariantSentenceId: true,
                variantSentenceId: null,
                hasVariantKind: true,
                variantKind: "\n",
                hasVariantStage: true,
                variantStage: null,
                hasVariantStatus: true,
                variantStatus: null,
                hasVariantUnlockedAt: true,
                variantUnlockedAt: null,
            ),
        );

        $this->assertTrue($result->wasUpdated);

        $card->refresh();
        $this->assertNull($card->variant_group_id);
        $this->assertNull($card->variant_sentence_id);
        $this->assertNull($card->variant_kind);
        $this->assertNull($card->variant_stage);
        $this->assertNull($card->variant_status);
        $this->assertNull($card->variant_unlocked_at);

        $entry = SyncFeedEntry::query()->sole();
        $this->assertEquals(CardSyncPayload::fromCard($card), $entry->payload);
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
        $this->assertSame('What is ATP? Cellular energy currency.', $result->card->search_text);
        $this->assertEquals(CardSyncPayload::fromCard($result->card->refresh()), SyncFeedEntry::query()->sole()->payload);
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

    public function test_it_derives_search_text_when_legacy_null_card_content_changes(): void
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
                frontText: 'arrivederci',
                backText: 'goodbye',
            ),
        );

        $this->assertTrue($result->wasUpdated);
        $this->assertSame('arrivederci goodbye', $result->card->search_text);
        $this->assertEquals(CardSyncPayload::fromCard($result->card->refresh()), SyncFeedEntry::query()->sole()->payload);
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

    public function test_it_marks_unchanged_when_variant_metadata_is_omitted(): void
    {
        $card = $this->cardFor($this->signIn(), [
            'front_text' => '会社',
            'back_text' => 'company',
            'variant_group_id' => 'keep-group',
            'variant_sentence_id' => 'keep-sentence',
            'variant_kind' => VocabVariantKind::SentenceAudioRecognition,
            'variant_stage' => 2,
            'variant_status' => VocabVariantStatus::Locked,
            'variant_unlocked_at' => Carbon::parse('2026-06-05T14:15:00Z'),
        ]);
        $card->refresh();

        $result = app(UpdateCardAction::class)->handle(
            $card,
            UpdateCardData::fromInput(
                frontText: '会社',
                backText: 'company',
            ),
        );

        $this->assertFalse($result->wasUpdated);

        $card->refresh();
        $this->assertSame('keep-group', $card->variant_group_id);
        $this->assertSame('keep-sentence', $card->variant_sentence_id);
        $this->assertSame(VocabVariantKind::SentenceAudioRecognition->value, $card->variant_kind);
        $this->assertSame(2, $card->variant_stage);
        $this->assertSame(VocabVariantStatus::Locked->value, $card->variant_status);
        $this->assertSame('2026-06-05T14:15:00.000000Z', $card->variant_unlocked_at?->toJSON());
        $this->assertDatabaseCount('sync_feed_entries', 0);
    }

    public function test_it_marks_unchanged_when_normalized_variant_metadata_matches_existing_values(): void
    {
        $card = $this->cardFor($this->signIn(), [
            'front_text' => '会社',
            'back_text' => 'company',
            'variant_group_id' => 'keep-group',
            'variant_sentence_id' => 'keep-sentence',
            'variant_kind' => VocabVariantKind::SentenceAudioRecognition,
            'variant_stage' => 2,
            'variant_status' => VocabVariantStatus::Locked,
            'variant_unlocked_at' => Carbon::parse('2026-06-05T14:15:00Z'),
        ]);
        $card->refresh();

        $result = app(UpdateCardAction::class)->handle(
            $card,
            UpdateCardData::fromInput(
                frontText: '会社',
                backText: 'company',
                hasVariantGroupId: true,
                variantGroupId: ' keep-group ',
                hasVariantSentenceId: true,
                variantSentenceId: ' keep-sentence ',
                hasVariantKind: true,
                variantKind: ' SENTENCE_AUDIO_RECOGNITION ',
                hasVariantStage: true,
                variantStage: 2,
                hasVariantStatus: true,
                variantStatus: ' LOCKED ',
                hasVariantUnlockedAt: true,
                variantUnlockedAt: Carbon::parse('2026-06-05T14:15:00.987654Z'),
            ),
        );

        $this->assertFalse($result->wasUpdated);
        $this->assertSame('2026-06-05T14:15:00.000000Z', $card->refresh()->variant_unlocked_at?->toJSON());
        $this->assertDatabaseCount('sync_feed_entries', 0);
    }

    public function test_it_allows_null_variant_stage_when_stage_is_present_for_direct_callers(): void
    {
        $data = UpdateCardData::fromInput(
            frontText: 'front',
            backText: 'back',
            hasVariantStage: true,
            variantStage: null,
        );

        $this->assertTrue($data->hasVariantStage);
        $this->assertNull($data->variantStage);
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

    /**
     * @param  array<string, mixed>  $overrides
     */
    #[DataProvider('invalidVariantMetadataProvider')]
    public function test_it_rejects_invalid_variant_metadata_for_direct_callers(array $overrides, string $message): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage($message);

        UpdateCardData::fromInput(...array_merge([
            'frontText' => 'front',
            'backText' => 'back',
        ], $overrides));
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidVariantMetadataProvider(): array
    {
        return [
            'oversized variant group id' => [
                ['hasVariantGroupId' => true, 'variantGroupId' => str_repeat('a', 65)],
                'Card variant IDs must be 64 characters or fewer.',
            ],
            'oversized variant sentence id' => [
                ['hasVariantSentenceId' => true, 'variantSentenceId' => str_repeat('a', 65)],
                'Card variant IDs must be 64 characters or fewer.',
            ],
            'malformed variant kind' => [
                ['hasVariantKind' => true, 'variantKind' => 'not-a-kind'],
                'Variant kind must be one of: sentence_audio_recognition, sentence_text_recognition, word_audio_recognition, word_text_recognition, sentence_cloze.',
            ],
            'malformed variant status' => [
                ['hasVariantStatus' => true, 'variantStatus' => 'unknown'],
                'Variant status must be one of: available, locked.',
            ],
            'negative variant stage' => [
                ['hasVariantStage' => true, 'variantStage' => -1],
                'Card variant stage must be between 1 and 65535.',
            ],
            'zero variant stage' => [
                ['hasVariantStage' => true, 'variantStage' => 0],
                'Card variant stage must be between 1 and 65535.',
            ],
            'oversized variant stage' => [
                ['hasVariantStage' => true, 'variantStage' => 65536],
                'Card variant stage must be between 1 and 65535.',
            ],
        ];
    }
}
