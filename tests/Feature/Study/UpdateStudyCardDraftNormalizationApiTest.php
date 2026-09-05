<?php

namespace Tests\Feature\Study;

use App\Domain\Study\Enums\StudyCardAudioRole;
use App\Domain\Study\Enums\StudyCardImagePlacement;
use App\Domain\Study\Models\StudyCardDraft;
use App\Domain\Sync\Enums\SyncFeedOperation;
use App\Domain\Vocabulary\Enums\VocabVariantKind;
use App\Domain\Vocabulary\Enums\VocabVariantStatus;
use Carbon\Carbon;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AssertsStudyCardDraftSyncFeedEntries;
use Tests\Support\AssertsStudyCompatibilityPayloads;
use Tests\TestCase;

class UpdateStudyCardDraftNormalizationApiTest extends TestCase
{
    use AssertsStudyCardDraftSyncFeedEntries;
    use AssertsStudyCompatibilityPayloads;
    use RefreshDatabase;

    public function test_it_normalizes_optional_fields_without_trim_strings_middleware(): void
    {
        $user = $this->signIn();
        $draft = StudyCardDraft::factory()->failed()->for($user)->create([
            'image_prompt' => 'Old prompt',
            'preview_audio_json' => ['id' => 'old-audio', 'filename' => 'old.mp3', 'mediaKind' => 'audio', 'source' => 'generated'],
            'preview_audio_role' => StudyCardAudioRole::Answer,
            'preview_image_json' => ['id' => 'old-image', 'filename' => 'old.webp', 'mediaKind' => 'image', 'source' => 'generated'],
            'variant_group_id' => 'old-group',
            'variant_sentence_id' => 'old-sentence',
            'variant_kind' => VocabVariantKind::SentenceTextRecognition,
            'variant_stage' => 1,
            'variant_status' => VocabVariantStatus::Locked,
            'variant_unlocked_at' => Carbon::parse('2026-06-05T14:15:00Z'),
        ]);

        $checkpoint = $this->assertNormalizedFields($draft);
        $this->assertClearedFields($draft, $checkpoint);
    }

    private function assertNormalizedFields(StudyCardDraft $draft): int
    {
        $response = $this
            ->withoutMiddleware(TrimStrings::class)
            ->patchJson("/api/study/card-drafts/{$draft->id}", [
                'prompt' => ['cueText' => '  company  '],
                'answer' => ['meaning' => '  会社  '],
                'imagePlacement' => ' BOTH ',
                'imagePrompt' => '   ',
                'previewAudio' => null,
                'previewAudioRole' => null,
                'previewImage' => null,
                'variantGroupId' => ' vocab-group-1 ',
                'variantSentenceId' => ' sentence-1 ',
                'variantKind' => ' SENTENCE_CLOZE ',
                'variantStage' => ' +3 ',
                'variantStatus' => ' AVAILABLE ',
                'variantUnlockedAt' => '2026-06-04T14:15:30+05:30',
            ])
            ->assertOk()
            ->assertJsonPath('prompt.cueText', '  company  ')
            ->assertJsonPath('answer.meaning', '  会社  ')
            ->assertJsonPath('imagePlacement', StudyCardImagePlacement::Both->value)
            ->assertJsonPath('imagePrompt', null)
            ->assertJsonPath('previewAudio', null)
            ->assertJsonPath('previewAudioRole', null)
            ->assertJsonPath('previewImage', null)
            ->assertJsonPath('variantGroupId', 'vocab-group-1')
            ->assertJsonPath('variantSentenceId', 'sentence-1')
            ->assertJsonPath('variantKind', VocabVariantKind::SentenceCloze->value)
            ->assertJsonPath('variantStage', 3)
            ->assertJsonPath('variantStatus', VocabVariantStatus::Available->value)
            ->assertJsonPath('variantUnlockedAt', '2026-06-04T08:45:30.000000Z');

        $this->assertStudyCardDraftCompatibilityPayloadHasShape($response->json());

        $draft->refresh();
        $this->assertNull($draft->image_prompt);
        $this->assertNull($draft->preview_audio_json);
        $this->assertNull($draft->preview_audio_role);
        $this->assertNull($draft->preview_image_json);
        $this->assertSame('vocab-group-1', $draft->variant_group_id);
        $this->assertSame('sentence-1', $draft->variant_sentence_id);
        $this->assertSame(VocabVariantKind::SentenceCloze->value, $draft->variant_kind);
        $this->assertSame(3, $draft->variant_stage);
        $this->assertSame(VocabVariantStatus::Available->value, $draft->variant_status);
        $this->assertSame('2026-06-04T08:45:30.000000Z', $draft->variant_unlocked_at?->toJSON());

        $firstEntry = $this->assertStudyCardDraftSyncPayloadRecorded($draft, SyncFeedOperation::Update);

        return $firstEntry->checkpoint;
    }

    private function assertClearedFields(StudyCardDraft $draft, int $checkpoint): void
    {
        $clearResponse = $this
            ->patchJson("/api/study/card-drafts/{$draft->id}", [
                'imagePlacement' => null,
                'variantGroupId' => null,
                'variantSentenceId' => null,
                'variantKind' => null,
                'variantStage' => null,
                'variantStatus' => null,
                'variantUnlockedAt' => null,
            ])
            ->assertOk()
            ->assertJsonPath('imagePlacement', StudyCardImagePlacement::None->value)
            ->assertJsonPath('variantGroupId', null)
            ->assertJsonPath('variantSentenceId', null)
            ->assertJsonPath('variantKind', null)
            ->assertJsonPath('variantStage', null)
            ->assertJsonPath('variantStatus', null)
            ->assertJsonPath('variantUnlockedAt', null);

        $this->assertStudyCardDraftCompatibilityPayloadHasShape($clearResponse->json());

        $this->assertSame(StudyCardImagePlacement::None, $draft->refresh()->image_placement);
        $this->assertNull($draft->variant_group_id);
        $this->assertNull($draft->variant_sentence_id);
        $this->assertNull($draft->variant_kind);
        $this->assertNull($draft->variant_stage);
        $this->assertNull($draft->variant_status);
        $this->assertNull($draft->variant_unlocked_at);

        $this->assertStudyCardDraftSyncPayloadRecorded(
            $draft,
            SyncFeedOperation::Update,
            afterCheckpoint: $checkpoint,
        );
    }
}
