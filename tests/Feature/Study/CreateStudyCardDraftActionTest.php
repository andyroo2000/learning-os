<?php

namespace Tests\Feature\Study;

use App\Domain\Flashcards\Enums\CardType;
use App\Domain\Study\Actions\CreateStudyCardDraftAction;
use App\Domain\Study\Data\CreateStudyCardDraftData;
use App\Domain\Study\Enums\StudyCardCreationKind;
use App\Domain\Study\Enums\StudyCardImagePlacement;
use App\Domain\Study\Enums\StudyManualCardDraftStatus;
use App\Domain\Study\Exceptions\StudyCardDraftConflictException;
use App\Domain\Study\Exceptions\StudyCardDraftValidationException;
use App\Domain\Sync\Enums\SyncFeedOperation;
use App\Domain\Vocabulary\Enums\VocabVariantKind;
use App\Domain\Vocabulary\Enums\VocabVariantStatus;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Study\Concerns\BuildsStudyCardDraftRows;
use Tests\Support\AssertsStudyCardDraftSyncFeedEntries;
use Tests\TestCase;

class CreateStudyCardDraftActionTest extends TestCase
{
    use AssertsStudyCardDraftSyncFeedEntries;
    use BuildsStudyCardDraftRows;
    use RefreshDatabase;

    public function test_it_creates_a_generating_study_card_draft(): void
    {
        $user = User::factory()->create();

        $draft = app(CreateStudyCardDraftAction::class)->handle(CreateStudyCardDraftData::fromInput(
            userId: $user->id,
            creationKind: StudyCardCreationKind::ProductionImage,
            cardType: CardType::Production,
            promptJson: ['cueText' => 'company'],
            answerJson: ['expression' => '会社', 'meaning' => 'company'],
            imagePlacement: StudyCardImagePlacement::Both,
            imagePrompt: '  A sunny office  ',
        ));

        $draft->refresh();

        $this->assertSame($user->id, $draft->user_id);
        $this->assertSame(StudyManualCardDraftStatus::Generating, $draft->status);
        $this->assertSame(StudyCardCreationKind::ProductionImage, $draft->creation_kind);
        $this->assertSame(CardType::Production, $draft->card_type);
        $this->assertSame(['cueText' => 'company'], $draft->prompt_json);
        $this->assertSame(['expression' => '会社', 'meaning' => 'company'], $draft->answer_json);
        $this->assertSame(StudyCardImagePlacement::Both, $draft->image_placement);
        $this->assertSame('A sunny office', $draft->image_prompt);
        $this->assertNull($draft->preview_audio_json);
        $this->assertNull($draft->preview_audio_role);
        $this->assertNull($draft->preview_image_json);
        $this->assertNull($draft->error_message);

        $this->assertDatabaseCount('sync_feed_entries', 1);

        $entry = $this->assertStudyCardDraftSyncPayloadRecorded($draft, SyncFeedOperation::Create);

        $this->assertSame('generating', $entry->payload['status']);
        $this->assertSame('production-image', $entry->payload['creation_kind']);
        $this->assertSame('A sunny office', $entry->payload['image_prompt']);
    }

    public function test_it_defaults_image_fields_for_direct_callers(): void
    {
        $user = User::factory()->create();

        $draft = app(CreateStudyCardDraftAction::class)->handle(CreateStudyCardDraftData::fromInput(
            userId: $user->id,
            creationKind: ' cloze ',
            cardType: ' CLOZE ',
            promptJson: ['clozeText' => '試合に[勝ちました]。'],
            answerJson: ['meaning' => 'won'],
            imagePrompt: '   ',
        ));

        $this->assertSame(StudyCardImagePlacement::None, $draft->refresh()->image_placement);
        $this->assertNull($draft->image_prompt);
    }

    public function test_it_persists_variant_metadata_for_direct_callers(): void
    {
        $unlockedAt = Carbon::parse('2026-06-04T14:15:30.987654+05:30');
        $expectedUnlockedAt = '2026-06-04T08:45:30.000000Z';

        $draft = app(CreateStudyCardDraftAction::class)->handle(CreateStudyCardDraftData::fromInput(
            userId: User::factory()->create()->id,
            creationKind: StudyCardCreationKind::TextRecognition,
            cardType: CardType::Recognition,
            promptJson: ['cueText' => '犬'],
            answerJson: ['meaning' => 'dog'],
            variantGroupId: ' vocab-group-1 ',
            variantSentenceId: ' sentence-1 ',
            variantKind: ' SENTENCE_AUDIO_RECOGNITION ',
            variantStage: 1,
            variantStatus: ' AVAILABLE ',
            variantUnlockedAt: $unlockedAt,
        ));

        $draft->refresh();

        $this->assertSame('vocab-group-1', $draft->variant_group_id);
        $this->assertSame('sentence-1', $draft->variant_sentence_id);
        $this->assertSame(VocabVariantKind::SentenceAudioRecognition->value, $draft->variant_kind);
        $this->assertSame(1, $draft->variant_stage);
        $this->assertSame(VocabVariantStatus::Available->value, $draft->variant_status);
        $this->assertSame($expectedUnlockedAt, $draft->variant_unlocked_at->toJSON());

        $this->assertDatabaseCount('sync_feed_entries', 1);

        $entry = $this->assertStudyCardDraftSyncPayloadRecorded($draft, SyncFeedOperation::Create);

        $this->assertSame('vocab-group-1', $entry->payload['variant_group_id']);
        $this->assertSame('sentence_audio_recognition', $entry->payload['variant_kind']);
        $this->assertSame($expectedUnlockedAt, $entry->payload['variant_unlocked_at']);
    }

    public function test_it_treats_blank_variant_enum_metadata_as_absent_for_direct_callers(): void
    {
        $draft = app(CreateStudyCardDraftAction::class)->handle(CreateStudyCardDraftData::fromInput(
            userId: User::factory()->create()->id,
            creationKind: StudyCardCreationKind::TextRecognition,
            cardType: CardType::Recognition,
            promptJson: ['cueText' => '犬'],
            answerJson: ['meaning' => 'dog'],
            variantGroupId: '   ',
            variantSentenceId: "\t",
            variantKind: '   ',
            variantStatus: "\n",
        ));

        $draft->refresh();

        $this->assertNull($draft->variant_group_id);
        $this->assertNull($draft->variant_sentence_id);
        $this->assertNull($draft->variant_kind);
        $this->assertNull($draft->variant_status);

        $this->assertDatabaseCount('sync_feed_entries', 1);

        $entry = $this->assertStudyCardDraftSyncPayloadRecorded($draft, SyncFeedOperation::Create);

        $this->assertNull($entry->payload['variant_group_id']);
        $this->assertNull($entry->payload['variant_kind']);
    }

    #[DataProvider('invalidVariantMetadataProvider')]
    public function test_it_rejects_invalid_variant_metadata_for_direct_callers(array $overrides, string $message): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage($message);

        CreateStudyCardDraftData::fromInput(...array_merge([
            'userId' => User::factory()->create()->id,
            'creationKind' => StudyCardCreationKind::TextRecognition,
            'cardType' => CardType::Recognition,
            'promptJson' => ['cueText' => '犬'],
            'answerJson' => ['meaning' => 'dog'],
        ], $overrides));
    }

    public function test_it_rejects_card_type_mismatches_for_direct_callers(): void
    {
        $this->expectException(StudyCardDraftValidationException::class);
        $this->expectExceptionMessage('cardType must match creationKind.');

        app(CreateStudyCardDraftAction::class)->handle(CreateStudyCardDraftData::fromInput(
            userId: User::factory()->create()->id,
            creationKind: StudyCardCreationKind::Cloze,
            cardType: CardType::Recognition,
            promptJson: ['clozeText' => '試合に[勝ちました]。'],
            answerJson: ['meaning' => 'won'],
        ));
    }

    public function test_it_rejects_invalid_creation_kinds_for_direct_callers_with_domain_validation(): void
    {
        $this->expectException(StudyCardDraftValidationException::class);
        $this->expectExceptionMessage('creationKind must be one of: text-recognition, audio-recognition, production-text, production-image, cloze.');

        CreateStudyCardDraftData::fromInput(
            userId: User::factory()->create()->id,
            creationKind: 'not-a-kind',
            cardType: CardType::Recognition,
            promptJson: ['cueText' => '犬'],
            answerJson: ['meaning' => 'dog'],
        );
    }

    public function test_it_rejects_invalid_image_placements_for_direct_callers_with_domain_validation(): void
    {
        $this->expectException(StudyCardDraftValidationException::class);
        $this->expectExceptionMessage('imagePlacement must be one of: none, prompt, answer, both.');

        CreateStudyCardDraftData::fromInput(
            userId: User::factory()->create()->id,
            creationKind: StudyCardCreationKind::TextRecognition,
            cardType: CardType::Recognition,
            promptJson: ['cueText' => '犬'],
            answerJson: ['meaning' => 'dog'],
            imagePlacement: 'sideways',
        );
    }

    public function test_it_rejects_oversized_image_prompts_for_direct_callers(): void
    {
        $this->expectException(StudyCardDraftValidationException::class);
        $this->expectExceptionMessage('imagePrompt must be 1000 characters or fewer.');

        app(CreateStudyCardDraftAction::class)->handle(CreateStudyCardDraftData::fromInput(
            userId: User::factory()->create()->id,
            creationKind: StudyCardCreationKind::TextRecognition,
            cardType: CardType::Recognition,
            promptJson: ['cueText' => '犬'],
            answerJson: ['meaning' => 'dog'],
            imagePrompt: str_repeat('a', CreateStudyCardDraftData::MAX_IMAGE_PROMPT_LENGTH + 1),
        ));
    }

    public function test_it_rejects_malformed_payload_shapes_for_direct_callers(): void
    {
        $this->expectException(StudyCardDraftValidationException::class);
        $this->expectExceptionMessage('prompt must be 8 levels deep or fewer.');

        app(CreateStudyCardDraftAction::class)->handle(CreateStudyCardDraftData::fromInput(
            userId: User::factory()->create()->id,
            creationKind: StudyCardCreationKind::TextRecognition,
            cardType: CardType::Recognition,
            promptJson: ['a' => ['b' => ['c' => ['d' => ['e' => ['f' => ['g' => ['h' => ['i' => 'deep']]]]]]]]],
            answerJson: ['meaning' => 'dog'],
        ));
    }

    #[DataProvider('nonPositiveUserIdProvider')]
    public function test_it_rejects_non_positive_user_ids_for_direct_callers(int $userId): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Study card draft user ID must be a positive integer.');

        CreateStudyCardDraftData::fromInput(
            userId: $userId,
            creationKind: StudyCardCreationKind::TextRecognition,
            cardType: CardType::Recognition,
            promptJson: ['cueText' => '犬'],
            answerJson: ['answerText' => 'dog'],
        );
    }

    public function test_it_rejects_creates_when_the_user_draft_queue_is_full(): void
    {
        $user = User::factory()->create();
        $this->insertCappedDraftRowsFor($user);

        $this->expectException(StudyCardDraftConflictException::class);
        $this->expectExceptionMessage('Draft queue is full. Delete some drafts before adding more.');

        app(CreateStudyCardDraftAction::class)->handle(CreateStudyCardDraftData::fromInput(
            userId: $user->id,
            creationKind: StudyCardCreationKind::TextRecognition,
            cardType: CardType::Recognition,
            promptJson: ['cueText' => '犬'],
            answerJson: ['answerText' => 'dog'],
        ));
    }

    /**
     * @return array<string, array{int}>
     */
    public static function nonPositiveUserIdProvider(): array
    {
        return [
            'zero' => [0],
            'negative' => [-1],
        ];
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidVariantMetadataProvider(): array
    {
        return [
            'oversized variant group id' => [
                ['variantGroupId' => str_repeat('a', 65)],
                'Study variant IDs must be 64 characters or fewer.',
            ],
            'oversized variant sentence id' => [
                ['variantSentenceId' => str_repeat('a', 65)],
                'Study variant IDs must be 64 characters or fewer.',
            ],
            'malformed variant kind' => [
                ['variantKind' => 'not-a-kind'],
                'Variant kind must be one of: sentence_audio_recognition, sentence_text_recognition, word_audio_recognition, word_text_recognition, sentence_cloze.',
            ],
            'malformed variant status' => [
                ['variantStatus' => 'unknown'],
                'Variant status must be one of: available, locked.',
            ],
            'zero variant stage' => [
                ['variantStage' => 0],
                'Study variant stage must be between 1 and 65535.',
            ],
            'negative variant stage' => [
                ['variantStage' => -1],
                'Study variant stage must be between 1 and 65535.',
            ],
            'oversized variant stage' => [
                ['variantStage' => 65536],
                'Study variant stage must be between 1 and 65535.',
            ],
        ];
    }
}
