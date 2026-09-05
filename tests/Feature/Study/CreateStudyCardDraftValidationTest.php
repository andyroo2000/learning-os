<?php

namespace Tests\Feature\Study;

use App\Domain\Flashcards\Enums\CardType;
use App\Domain\Study\Actions\CreateStudyCardDraftAction;
use App\Domain\Study\Data\CreateStudyCardDraftData;
use App\Domain\Study\Enums\StudyCardCreationKind;
use App\Domain\Study\Enums\StudyCardImagePlacement;
use App\Domain\Study\Exceptions\StudyCardDraftValidationException;
use App\Domain\Sync\Enums\SyncFeedOperation;
use App\Domain\Vocabulary\Enums\VocabVariantKind;
use App\Domain\Vocabulary\Enums\VocabVariantStatus;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AssertsStudyCardDraftSyncFeedEntries;
use Tests\TestCase;

class CreateStudyCardDraftValidationTest extends TestCase
{
    use AssertsStudyCardDraftSyncFeedEntries;
    use RefreshDatabase;

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

        CreateStudyCardDraftData::fromInput(...array_merge($this->validInput(), $overrides));
    }

    public function test_it_rejects_card_type_mismatches_for_direct_callers(): void
    {
        $this->expectException(StudyCardDraftValidationException::class);
        $this->expectExceptionMessage('cardType must match creationKind.');

        app(CreateStudyCardDraftAction::class)->handle(CreateStudyCardDraftData::fromInput(
            ...array_merge($this->validInput(), [
                'creationKind' => StudyCardCreationKind::Cloze,
                'cardType' => CardType::Recognition,
                'promptJson' => ['clozeText' => '試合に[勝ちました]。'],
                'answerJson' => ['meaning' => 'won'],
            ]),
        ));
    }

    public function test_it_rejects_wrong_types_for_owned_payload_fields_for_direct_callers(): void
    {
        try {
            CreateStudyCardDraftData::fromInput(
                ...array_merge($this->validInput(), [
                    'promptJson' => ['cueText' => '犬', 'cueReading' => ['not text']],
                ]),
            );

            $this->fail('Expected a payload validation exception.');
        } catch (StudyCardDraftValidationException $exception) {
            $this->assertSame('prompt.cueReading', $exception->field());
            $this->assertSame('prompt.cueReading must be a string or null.', $exception->getMessage());
        }
    }

    #[DataProvider('invalidEnumInputProvider')]
    public function test_it_rejects_invalid_enum_input_for_direct_callers(array $overrides, string $message): void
    {
        $this->expectException(StudyCardDraftValidationException::class);
        $this->expectExceptionMessage($message);

        CreateStudyCardDraftData::fromInput(...array_merge($this->validInput(), $overrides));
    }

    #[DataProvider('invalidActionInputProvider')]
    public function test_it_rejects_invalid_action_input_before_side_effects(array $overrides, string $message): void
    {
        $this->expectException(StudyCardDraftValidationException::class);
        $this->expectExceptionMessage($message);

        app(CreateStudyCardDraftAction::class)->handle(
            CreateStudyCardDraftData::fromInput(...array_merge($this->validInput(), $overrides)),
        );
    }

    #[DataProvider('nonPositiveUserIdProvider')]
    public function test_it_rejects_non_positive_user_ids_for_direct_callers(int $userId): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Study card draft user ID must be a positive integer.');

        CreateStudyCardDraftData::fromInput(...$this->validInput($userId));
    }

    /** @return array<string, mixed> */
    private function validInput(?int $userId = null): array
    {
        return [
            'userId' => $userId ?? User::factory()->create()->id,
            'creationKind' => StudyCardCreationKind::TextRecognition,
            'cardType' => CardType::Recognition,
            'promptJson' => ['cueText' => '犬'],
            'answerJson' => ['meaning' => 'dog'],
        ];
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function invalidEnumInputProvider(): array
    {
        $creationKindMessage = 'creationKind must be one of: '.implode(', ', StudyCardCreationKind::values()).'.';
        $imagePlacementMessage = 'imagePlacement must be one of: '.implode(', ', StudyCardImagePlacement::values()).'.';

        return [
            'invalid creation kind' => [['creationKind' => 'not-a-kind'], $creationKindMessage],
            'blank creation kind' => [['creationKind' => '   '], $creationKindMessage],
            'invalid image placement' => [['imagePlacement' => 'sideways'], $imagePlacementMessage],
            'blank image placement' => [['imagePlacement' => '   '], $imagePlacementMessage],
        ];
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function invalidActionInputProvider(): array
    {
        return [
            'invalid client id' => [
                ['id' => ' not-a-ulid '],
                'id must be a valid ULID.',
            ],
            'oversized image prompt' => [
                ['imagePrompt' => str_repeat('a', CreateStudyCardDraftData::MAX_IMAGE_PROMPT_LENGTH + 1)],
                'imagePrompt must be 1000 characters or fewer.',
            ],
            'malformed prompt shape' => [
                ['promptJson' => ['a' => ['b' => ['c' => ['d' => ['e' => ['f' => ['g' => ['h' => ['i' => 'deep']]]]]]]]]],
                'prompt must be 8 levels deep or fewer.',
            ],
        ];
    }

    /** @return array<string, array{int}> */
    public static function nonPositiveUserIdProvider(): array
    {
        return [
            'zero' => [0],
            'negative' => [-1],
        ];
    }

    /** @return array<string, array{array<string, mixed>, string}> */
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
                'Variant kind must be one of: sentence_audio_recognition, sentence_text_recognition, word_audio_recognition, word_text_recognition, sentence_cloze, sentence_production.',
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
