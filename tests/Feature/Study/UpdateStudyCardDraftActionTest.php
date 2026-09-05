<?php

namespace Tests\Feature\Study;

use App\Domain\Study\Actions\UpdateStudyCardDraftAction;
use App\Domain\Study\Data\UpdateStudyCardDraftData;
use App\Domain\Study\Enums\StudyCardAudioRole;
use App\Domain\Study\Enums\StudyCardImagePlacement;
use App\Domain\Study\Enums\StudyManualCardDraftStatus;
use App\Domain\Study\Models\StudyCardDraft;
use App\Domain\Sync\Enums\SyncFeedOperation;
use App\Domain\Sync\Models\SyncFeedEntry;
use App\Domain\Vocabulary\Enums\VocabVariantKind;
use App\Domain\Vocabulary\Enums\VocabVariantStatus;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AssertsStudyCardDraftSyncFeedEntries;
use Tests\TestCase;

class UpdateStudyCardDraftActionTest extends TestCase
{
    use AssertsStudyCardDraftSyncFeedEntries;
    use RefreshDatabase;

    public function test_it_autosaves_ready_study_card_draft_fields(): void
    {
        $draft = StudyCardDraft::factory()->ready()->create([
            'prompt_json' => ['cueText' => '会社'],
            'answer_json' => ['expression' => '会社', 'meaning' => 'company'],
            'image_prompt' => 'Old prompt',
            'error_message' => 'Old error.',
        ]);

        $updated = app(UpdateStudyCardDraftAction::class)->handle($draft, $this->readyDraftUpdateData());

        $updated->refresh();

        $this->assertSame(['cueText' => '会議'], $updated->prompt_json);
        $this->assertSame(['expression' => '会議', 'meaning' => 'meeting'], $updated->answer_json);
        $this->assertSame(StudyCardImagePlacement::Answer, $updated->image_placement);
        $this->assertSame('A quiet meeting room', $updated->image_prompt);
        $this->assertSame('audio-1', $updated->preview_audio_json['id']);
        $this->assertSame(StudyCardAudioRole::Prompt, $updated->preview_audio_role);
        $this->assertSame('image-1', $updated->preview_image_json['id']);
        $this->assertSame('vocab-group-1', $updated->variant_group_id);
        $this->assertSame('sentence-1', $updated->variant_sentence_id);
        $this->assertSame(VocabVariantKind::SentenceCloze->value, $updated->variant_kind);
        $this->assertSame(3, $updated->variant_stage);
        $this->assertSame(VocabVariantStatus::Available->value, $updated->variant_status);
        $this->assertSame('2026-06-04T08:45:30.000000Z', $updated->variant_unlocked_at?->toJSON());
        $this->assertSame(StudyManualCardDraftStatus::Ready, $updated->status);
        $this->assertSame('Old error.', $updated->error_message);

        $this->assertDatabaseCount('sync_feed_entries', 1);

        $entry = $this->assertStudyCardDraftSyncPayloadRecorded($updated, SyncFeedOperation::Update);

        $this->assertSame('ready', $entry->payload['status']);
        $this->assertSame('A quiet meeting room', $entry->payload['image_prompt']);
        $this->assertSame('sentence_cloze', $entry->payload['variant_kind']);
    }

    public function test_it_ignores_values_for_omitted_fields_in_direct_data(): void
    {
        $draft = StudyCardDraft::factory()->ready()->create([
            'image_prompt' => 'Keep this',
            'preview_audio_json' => [
                'filename' => 'keep.mp3',
                'mediaKind' => 'audio',
                'source' => 'generated',
            ],
            'preview_image_json' => [
                'filename' => 'keep.webp',
                'mediaKind' => 'image',
                'source' => 'generated',
            ],
            'variant_group_id' => 'keep-group',
            'variant_sentence_id' => 'keep-sentence',
            'variant_kind' => VocabVariantKind::SentenceAudioRecognition,
            'variant_stage' => 2,
            'variant_status' => VocabVariantStatus::Locked,
            'variant_unlocked_at' => Carbon::parse('2026-06-05T14:15:00Z'),
        ]);

        $updated = app(UpdateStudyCardDraftAction::class)->handle($draft, $this->omittedFieldsUpdateData());

        $updated->refresh();

        $this->assertSame('Keep this', $updated->image_prompt);
        $this->assertSame('keep.mp3', $updated->preview_audio_json['filename']);
        $this->assertSame('keep.webp', $updated->preview_image_json['filename']);
        $this->assertSame('keep-group', $updated->variant_group_id);
        $this->assertSame('keep-sentence', $updated->variant_sentence_id);
        $this->assertSame(VocabVariantKind::SentenceAudioRecognition->value, $updated->variant_kind);
        $this->assertSame(2, $updated->variant_stage);
        $this->assertSame(VocabVariantStatus::Locked->value, $updated->variant_status);
        $this->assertSame('2026-06-05T14:15:00.000000Z', $updated->variant_unlocked_at?->toJSON());
    }

    public function test_it_does_not_record_sync_entries_for_noop_resubmits(): void
    {
        $draft = StudyCardDraft::factory()->ready()->create([
            'prompt_json' => ['cueText' => '会社'],
            'answer_json' => ['expression' => '会社', 'meaning' => 'company'],
            'image_placement' => StudyCardImagePlacement::Both,
            'image_prompt' => 'Keep this',
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
            'variant_group_id' => 'keep-group',
            'variant_sentence_id' => 'keep-sentence',
            'variant_kind' => VocabVariantKind::SentenceAudioRecognition,
            'variant_stage' => 2,
            'variant_status' => VocabVariantStatus::Locked,
            'variant_unlocked_at' => Carbon::parse('2026-06-05T14:15:00Z'),
        ]);
        $this->assertNotNull($draft->updated_at);
        $originalUpdatedAt = $draft->updated_at->toJSON();

        $updated = app(UpdateStudyCardDraftAction::class)->handle($draft, $this->unchangedDraftUpdateData());

        $updated->refresh();

        $this->assertNotNull($updated->updated_at);
        $this->assertSame($originalUpdatedAt, $updated->updated_at->toJSON());
        $this->assertSame(0, SyncFeedEntry::query()->count());
    }

    private function readyDraftUpdateData(): UpdateStudyCardDraftData
    {
        return UpdateStudyCardDraftData::fromInput(
            hasPrompt: true,
            promptJson: ['cueText' => '会議'],
            hasAnswer: true,
            answerJson: ['expression' => '会議', 'meaning' => 'meeting'],
            hasImagePlacement: true,
            imagePlacement: StudyCardImagePlacement::Answer,
            hasImagePrompt: true,
            imagePrompt: '  A quiet meeting room  ',
            hasPreviewAudio: true,
            previewAudioJson: [
                'id' => 'audio-1',
                'filename' => 'kaigi.mp3',
                'url' => '/api/study/media/audio-1',
                'mediaKind' => 'audio',
                'source' => 'generated',
            ],
            hasPreviewAudioRole: true,
            previewAudioRole: StudyCardAudioRole::Prompt,
            hasPreviewImage: true,
            previewImageJson: [
                'id' => 'image-1',
                'filename' => 'kaigi.webp',
                'url' => '/api/study/media/image-1',
                'mediaKind' => 'image',
                'source' => 'generated',
            ],
            hasVariantGroupId: true,
            variantGroupId: ' vocab-group-1 ',
            hasVariantSentenceId: true,
            variantSentenceId: ' sentence-1 ',
            hasVariantKind: true,
            variantKind: ' SENTENCE_CLOZE ',
            hasVariantStage: true,
            variantStage: 3,
            hasVariantStatus: true,
            variantStatus: ' AVAILABLE ',
            hasVariantUnlockedAt: true,
            variantUnlockedAt: Carbon::parse('2026-06-04T14:15:30.987654+05:30'),
        );
    }

    private function omittedFieldsUpdateData(): UpdateStudyCardDraftData
    {
        return UpdateStudyCardDraftData::fromInput(
            hasImagePrompt: false,
            imagePrompt: str_repeat('a', 1001),
            hasPreviewAudio: false,
            previewAudioJson: [
                'filename' => '',
                'mediaKind' => 'image',
                'source' => 'external',
            ],
            hasPreviewImage: false,
            previewImageJson: [
                'filename' => '',
                'mediaKind' => 'audio',
                'source' => 'external',
            ],
            hasVariantGroupId: false,
            variantGroupId: str_repeat('a', 65),
            hasVariantSentenceId: false,
            variantSentenceId: str_repeat('b', 65),
            hasVariantKind: false,
            variantKind: 'not-a-kind',
            hasVariantStage: false,
            variantStage: 0,
            hasVariantStatus: false,
            variantStatus: 'not-a-status',
            hasVariantUnlockedAt: false,
            variantUnlockedAt: Carbon::parse('2026-06-05T14:15:00Z'),
        );
    }

    private function unchangedDraftUpdateData(): UpdateStudyCardDraftData
    {
        return UpdateStudyCardDraftData::fromInput(
            hasPrompt: true,
            promptJson: ['cueText' => '会社'],
            hasAnswer: true,
            answerJson: ['expression' => '会社', 'meaning' => 'company'],
            hasImagePlacement: true,
            imagePlacement: StudyCardImagePlacement::Both,
            hasImagePrompt: true,
            imagePrompt: 'Keep this',
            hasPreviewAudio: true,
            previewAudioJson: [
                'id' => 'audio-1',
                'filename' => 'kaisha.mp3',
                'url' => '/api/study/media/audio-1',
                'mediaKind' => 'audio',
                'source' => 'generated',
            ],
            hasPreviewAudioRole: true,
            previewAudioRole: StudyCardAudioRole::Answer,
            hasPreviewImage: true,
            previewImageJson: [
                'id' => 'image-1',
                'filename' => 'kaisha.webp',
                'url' => '/api/study/media/image-1',
                'mediaKind' => 'image',
                'source' => 'generated',
            ],
            hasVariantGroupId: true,
            variantGroupId: 'keep-group',
            hasVariantSentenceId: true,
            variantSentenceId: 'keep-sentence',
            hasVariantKind: true,
            variantKind: VocabVariantKind::SentenceAudioRecognition,
            hasVariantStage: true,
            variantStage: 2,
            hasVariantStatus: true,
            variantStatus: VocabVariantStatus::Locked,
            hasVariantUnlockedAt: true,
            variantUnlockedAt: Carbon::parse('2026-06-05T14:15:00Z'),
        );
    }
}
