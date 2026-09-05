<?php

namespace Tests\Feature\Study;

use App\Domain\Study\Actions\UpdateStudyCardDraftAction;
use App\Domain\Study\Data\UpdateStudyCardDraftData;
use App\Domain\Study\Enums\StudyCardAudioRole;
use App\Domain\Study\Enums\StudyCardImagePlacement;
use App\Domain\Study\Models\StudyCardDraft;
use App\Domain\Sync\Enums\SyncFeedOperation;
use App\Domain\Sync\Models\SyncFeedEntry;
use App\Domain\Vocabulary\Enums\VocabVariantKind;
use App\Domain\Vocabulary\Enums\VocabVariantStatus;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AssertsStudyCardDraftSyncFeedEntries;
use Tests\TestCase;

class UpdateStudyCardDraftNullableActionTest extends TestCase
{
    use AssertsStudyCardDraftSyncFeedEntries;
    use RefreshDatabase;

    public function test_it_clears_nullable_optional_fields_for_direct_callers(): void
    {
        $draft = StudyCardDraft::factory()->ready()->create([
            'image_placement' => StudyCardImagePlacement::Both,
            'image_prompt' => 'Keep this',
            'variant_group_id' => 'vocab-group-1',
            'variant_sentence_id' => 'sentence-1',
            'variant_kind' => VocabVariantKind::SentenceTextRecognition,
            'variant_stage' => 1,
            'variant_status' => VocabVariantStatus::Available,
            'variant_unlocked_at' => Carbon::parse('2026-06-05T14:15:00Z'),
            'preview_audio_json' => [
                'id' => 'audio-1',
                'filename' => 'kaisha.mp3',
                'url' => '/api/study/media/audio-1',
                'mediaKind' => 'audio',
                'source' => 'generated',
            ],
            'preview_audio_role' => StudyCardAudioRole::Answer,
            'preview_image_json' => [
                'id' => 'image-1',
                'filename' => 'kaisha.webp',
                'url' => '/api/study/media/image-1',
                'mediaKind' => 'image',
                'source' => 'generated',
            ],
        ]);

        $updated = app(UpdateStudyCardDraftAction::class)->handle($draft, UpdateStudyCardDraftData::fromInput(
            hasImagePlacement: true,
            imagePlacement: null,
            hasImagePrompt: true,
            imagePrompt: '   ',
            hasPreviewAudio: true,
            previewAudioJson: null,
            hasPreviewAudioRole: true,
            previewAudioRole: null,
            hasPreviewImage: true,
            previewImageJson: null,
            hasVariantGroupId: true,
            variantGroupId: '   ',
            hasVariantSentenceId: true,
            variantSentenceId: null,
            hasVariantKind: true,
            variantKind: null,
            hasVariantStage: true,
            variantStage: null,
            hasVariantStatus: true,
            variantStatus: null,
            hasVariantUnlockedAt: true,
            variantUnlockedAt: null,
        ));

        $updated->refresh();

        $this->assertSame(StudyCardImagePlacement::None, $updated->image_placement);
        $this->assertNull($updated->image_prompt);
        $this->assertNull($updated->preview_audio_json);
        $this->assertNull($updated->preview_audio_role);
        $this->assertNull($updated->preview_image_json);
        $this->assertNull($updated->variant_group_id);
        $this->assertNull($updated->variant_sentence_id);
        $this->assertNull($updated->variant_kind);
        $this->assertNull($updated->variant_stage);
        $this->assertNull($updated->variant_status);
        $this->assertNull($updated->variant_unlocked_at);

        $this->assertDatabaseCount('sync_feed_entries', 1);

        $entry = $this->assertStudyCardDraftSyncPayloadRecorded($updated, SyncFeedOperation::Update);

        $this->assertSame('none', $entry->payload['image_placement']);
        $this->assertNull($entry->payload['variant_group_id']);
        $this->assertNull($entry->payload['variant_unlocked_at']);
    }

    public function test_repeated_nullable_clears_are_noop_resubmits_without_sync_entries(): void
    {
        $draft = StudyCardDraft::factory()->ready()->create([
            'image_placement' => StudyCardImagePlacement::None,
            'image_prompt' => null,
            'preview_audio_json' => null,
            'preview_audio_role' => null,
            'preview_image_json' => null,
            'variant_group_id' => null,
            'variant_sentence_id' => null,
            'variant_kind' => null,
            'variant_stage' => null,
            'variant_status' => null,
            'variant_unlocked_at' => null,
        ]);
        $this->assertNotNull($draft->updated_at);
        $originalUpdatedAt = $draft->updated_at->toJSON();

        $updated = app(UpdateStudyCardDraftAction::class)->handle($draft, UpdateStudyCardDraftData::fromInput(
            hasImagePlacement: true,
            imagePlacement: null,
            hasImagePrompt: true,
            imagePrompt: null,
            hasPreviewAudio: true,
            previewAudioJson: null,
            hasPreviewAudioRole: true,
            previewAudioRole: null,
            hasPreviewImage: true,
            previewImageJson: null,
            hasVariantGroupId: true,
            variantGroupId: null,
            hasVariantSentenceId: true,
            variantSentenceId: null,
            hasVariantKind: true,
            variantKind: null,
            hasVariantStage: true,
            variantStage: null,
            hasVariantStatus: true,
            variantStatus: null,
            hasVariantUnlockedAt: true,
            variantUnlockedAt: null,
        ));

        $updated->refresh();

        $this->assertNotNull($updated->updated_at);
        $this->assertSame($originalUpdatedAt, $updated->updated_at->toJSON());
        $this->assertSame(StudyCardImagePlacement::None, $updated->image_placement);
        $this->assertNull($updated->preview_audio_json);
        $this->assertNull($updated->preview_audio_role);
        $this->assertNull($updated->preview_image_json);
        $this->assertSame(0, SyncFeedEntry::query()->count());
    }
}
