<?php

namespace Tests\Feature\Flashcards;

use App\Domain\Flashcards\Actions\UpdateCardAction;
use App\Domain\Flashcards\Data\UpdateCardData;
use App\Domain\Sync\Enums\SyncFeedOperation;
use App\Domain\Vocabulary\Enums\VocabVariantKind;
use App\Domain\Vocabulary\Enums\VocabVariantStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AssertsCardSyncFeedEntries;
use Tests\TestCase;

class UpdateCardVariantActionTest extends TestCase
{
    use AssertsCardSyncFeedEntries;
    use RefreshDatabase;

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

        $this->assertDatabaseCount('sync_feed_entries', 1);

        $entry = $this->assertCardSyncPayloadRecorded($card, SyncFeedOperation::Update);

        $this->assertSame('vocab-group-1', $entry->payload['variant_group_id']);
        $this->assertSame('sentence_cloze', $entry->payload['variant_kind']);
        $this->assertSame('2026-06-04T08:45:30.000000Z', $entry->payload['variant_unlocked_at']);
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

        $this->assertDatabaseCount('sync_feed_entries', 1);

        $entry = $this->assertCardSyncPayloadRecorded($card, SyncFeedOperation::Update);

        $this->assertNull($entry->payload['variant_group_id']);
        $this->assertNull($entry->payload['variant_kind']);
        $this->assertNull($entry->payload['variant_unlocked_at']);
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
                'Variant kind must be one of: sentence_audio_recognition, sentence_text_recognition, word_audio_recognition, word_text_recognition, sentence_cloze, sentence_production.',
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
