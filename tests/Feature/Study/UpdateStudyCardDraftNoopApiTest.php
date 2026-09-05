<?php

namespace Tests\Feature\Study;

use App\Domain\Study\Enums\StudyCardAudioRole;
use App\Domain\Study\Enums\StudyCardImagePlacement;
use App\Domain\Study\Models\StudyCardDraft;
use App\Domain\Sync\Models\SyncFeedEntry;
use App\Domain\Vocabulary\Enums\VocabVariantKind;
use App\Domain\Vocabulary\Enums\VocabVariantStatus;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AssertsStudyCompatibilityPayloads;
use Tests\TestCase;

class UpdateStudyCardDraftNoopApiTest extends TestCase
{
    use AssertsStudyCompatibilityPayloads;
    use RefreshDatabase;

    public function test_empty_autosave_is_a_noop_and_returns_the_current_draft(): void
    {
        $user = $this->signIn();
        $draft = StudyCardDraft::factory()->ready()->for($user)->create([
            'prompt_json' => ['cueText' => '会社'],
            'answer_json' => ['meaning' => 'company'],
            'image_prompt' => 'Keep this',
        ]);
        $this->assertNotNull($draft->updated_at);
        $originalUpdatedAt = $draft->updated_at->toJSON();

        // Autosave clients can submit an empty body after debounced form churn; keep it a harmless readback.
        $response = $this->patchJson("/api/study/card-drafts/{$draft->id}", [])
            ->assertOk()
            ->assertJsonPath('revision', 0)
            ->assertJsonPath('prompt.cueText', '会社')
            ->assertJsonPath('answer.meaning', 'company')
            ->assertJsonPath('imagePrompt', 'Keep this')
            ->assertJsonPath('updatedAt', $originalUpdatedAt);

        $this->assertStudyCardDraftCompatibilityPayloadHasShape($response->json());

        $draft->refresh();

        $this->assertNotNull($draft->updated_at);
        $this->assertSame($originalUpdatedAt, $draft->updated_at->toJSON());
        $this->assertSame(0, SyncFeedEntry::query()->count());
    }

    public function test_identical_autosave_resubmits_preserve_timestamp_and_do_not_record_sync(): void
    {
        $user = $this->signIn();
        $draft = StudyCardDraft::factory()->ready()->for($user)->create([
            'prompt_json' => ['cueText' => '会社'],
            'answer_json' => ['meaning' => 'company'],
            'image_placement' => StudyCardImagePlacement::Both,
            'image_prompt' => 'Keep this',
            'preview_audio_json' => ['id' => 'audio-1', 'filename' => 'keep.mp3', 'mediaKind' => 'audio', 'source' => 'generated'],
            'preview_audio_role' => StudyCardAudioRole::Answer,
            'preview_image_json' => ['id' => 'image-1', 'filename' => 'keep.webp', 'mediaKind' => 'image', 'source' => 'generated'],
            'variant_group_id' => 'keep-group',
            'variant_sentence_id' => 'keep-sentence',
            'variant_kind' => VocabVariantKind::SentenceAudioRecognition,
            'variant_stage' => 2,
            'variant_status' => VocabVariantStatus::Locked,
            'variant_unlocked_at' => Carbon::parse('2026-06-05T14:15:00Z'),
        ]);
        $this->assertNotNull($draft->updated_at);
        $originalUpdatedAt = $draft->updated_at->toJSON();

        $response = $this->patchJson("/api/study/card-drafts/{$draft->id}", [
            'prompt' => ['cueText' => '会社'],
            'answer' => ['meaning' => 'company'],
            'imagePlacement' => 'both',
            'imagePrompt' => 'Keep this',
            'previewAudio' => ['id' => 'audio-1', 'filename' => 'keep.mp3', 'mediaKind' => 'audio', 'source' => 'generated'],
            'previewAudioRole' => 'answer',
            'previewImage' => ['id' => 'image-1', 'filename' => 'keep.webp', 'mediaKind' => 'image', 'source' => 'generated'],
            'variantGroupId' => 'keep-group',
            'variantSentenceId' => 'keep-sentence',
            'variantKind' => 'sentence_audio_recognition',
            'variantStage' => 2,
            'variantStatus' => 'locked',
            'variantUnlockedAt' => '2026-06-05T14:15:00Z',
        ])
            ->assertOk()
            ->assertJsonPath('updatedAt', $originalUpdatedAt)
            ->assertJsonPath('previewAudio.id', 'audio-1')
            ->assertJsonPath('previewAudioRole', StudyCardAudioRole::Answer->value)
            ->assertJsonPath('previewImage.id', 'image-1')
            ->assertJsonPath('variantKind', VocabVariantKind::SentenceAudioRecognition->value);

        $this->assertStudyCardDraftCompatibilityPayloadHasShape($response->json());

        $draft->refresh();

        $this->assertNotNull($draft->updated_at);
        $this->assertSame($originalUpdatedAt, $draft->updated_at->toJSON());
        $this->assertSame(0, SyncFeedEntry::query()->count());
    }
}
