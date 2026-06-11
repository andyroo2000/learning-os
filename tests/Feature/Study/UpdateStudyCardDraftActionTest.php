<?php

namespace Tests\Feature\Study;

use App\Domain\Study\Actions\UpdateStudyCardDraftAction;
use App\Domain\Study\Data\UpdateStudyCardDraftData;
use App\Domain\Study\Enums\StudyCardAudioRole;
use App\Domain\Study\Enums\StudyCardImagePlacement;
use App\Domain\Study\Enums\StudyManualCardDraftStatus;
use App\Domain\Study\Exceptions\StudyCardDraftConflictException;
use App\Domain\Study\Exceptions\StudyCardDraftNotFoundException;
use App\Domain\Study\Exceptions\StudyCardDraftValidationException;
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

        $updated = app(UpdateStudyCardDraftAction::class)->handle($draft, UpdateStudyCardDraftData::fromInput(
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
        ));

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

        $updated = app(UpdateStudyCardDraftAction::class)->handle($draft, UpdateStudyCardDraftData::fromInput(
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
        ));

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
            'variant_group_id' => 'keep-group',
            'variant_sentence_id' => 'keep-sentence',
            'variant_kind' => VocabVariantKind::SentenceAudioRecognition,
            'variant_stage' => 2,
            'variant_status' => VocabVariantStatus::Locked,
            'variant_unlocked_at' => Carbon::parse('2026-06-05T14:15:00Z'),
        ]);

        app(UpdateStudyCardDraftAction::class)->handle($draft, UpdateStudyCardDraftData::fromInput(
            hasPrompt: true,
            promptJson: ['cueText' => '会社'],
            hasAnswer: true,
            answerJson: ['expression' => '会社', 'meaning' => 'company'],
            hasImagePlacement: true,
            imagePlacement: StudyCardImagePlacement::Both,
            hasImagePrompt: true,
            imagePrompt: 'Keep this',
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
        ));

        $this->assertSame(0, SyncFeedEntry::query()->count());
    }

    public function test_empty_direct_autosave_is_a_readback_for_generating_drafts(): void
    {
        $draft = StudyCardDraft::factory()->create([
            'status' => StudyManualCardDraftStatus::Generating,
            'prompt_json' => ['cueText' => '会社'],
            'answer_json' => ['meaning' => 'company'],
        ]);
        $originalUpdatedAt = $draft->updated_at?->toJSON();

        $updated = app(UpdateStudyCardDraftAction::class)->handle($draft, UpdateStudyCardDraftData::fromInput());

        $this->assertSame($draft->id, $updated->id);
        $this->assertSame(StudyManualCardDraftStatus::Generating, $updated->status);
        $this->assertSame(['cueText' => '会社'], $updated->prompt_json);
        $this->assertSame(['meaning' => 'company'], $updated->answer_json);
        $this->assertSame($originalUpdatedAt, $updated->updated_at?->toJSON());
        $this->assertSame(0, SyncFeedEntry::query()->count());
    }

    public function test_it_only_updates_present_fields(): void
    {
        $draft = StudyCardDraft::factory()->failed()->create([
            'prompt_json' => ['cueText' => '会社'],
            'answer_json' => ['expression' => '会社', 'meaning' => 'company'],
            'image_placement' => StudyCardImagePlacement::Both,
            'image_prompt' => 'Keep this',
        ]);

        $updated = app(UpdateStudyCardDraftAction::class)->handle($draft, UpdateStudyCardDraftData::fromInput(
            hasAnswer: true,
            answerJson: ['expression' => '会社', 'meaning' => 'business'],
            hasPrompt: true,
            promptJson: ['cueText' => '会社'],
        ));

        $updated->refresh();

        $this->assertSame(['cueText' => '会社'], $updated->prompt_json);
        $this->assertSame(['expression' => '会社', 'meaning' => 'business'], $updated->answer_json);
        $this->assertSame(StudyCardImagePlacement::Both, $updated->image_placement);
        $this->assertSame('Keep this', $updated->image_prompt);
        $this->assertSame(StudyManualCardDraftStatus::Error, $updated->status);
    }

    public function test_it_requires_effective_preview_audio_before_setting_audio_role(): void
    {
        $draft = StudyCardDraft::factory()->ready()->create([
            'preview_audio_json' => null,
            'preview_audio_role' => null,
        ]);

        $this->expectException(StudyCardDraftValidationException::class);
        $this->expectExceptionMessage('previewAudioRole requires previewAudio.');

        app(UpdateStudyCardDraftAction::class)->handle($draft, UpdateStudyCardDraftData::fromInput(
            hasPreviewAudioRole: true,
            previewAudioRole: StudyCardAudioRole::Prompt,
        ));
    }

    public function test_it_allows_audio_role_updates_when_preview_audio_already_exists(): void
    {
        $draft = StudyCardDraft::factory()->ready()->create([
            'preview_audio_json' => [
                'id' => 'audio-1',
                'filename' => 'kaisha.mp3',
                'url' => '/api/study/media/audio-1',
                'mediaKind' => 'audio',
                'source' => 'generated',
            ],
            'preview_audio_role' => StudyCardAudioRole::Answer,
        ]);

        $updated = app(UpdateStudyCardDraftAction::class)->handle($draft, UpdateStudyCardDraftData::fromInput(
            hasPreviewAudioRole: true,
            previewAudioRole: StudyCardAudioRole::Prompt,
        ));

        $this->assertSame('audio-1', $updated->refresh()->preview_audio_json['id']);
        $this->assertSame(StudyCardAudioRole::Prompt, $updated->preview_audio_role);
    }

    public function test_it_clears_audio_role_when_preview_audio_is_cleared(): void
    {
        $draft = StudyCardDraft::factory()->ready()->create([
            'preview_audio_json' => [
                'id' => 'audio-1',
                'filename' => 'kaisha.mp3',
                'url' => '/api/study/media/audio-1',
                'mediaKind' => 'audio',
                'source' => 'generated',
            ],
            'preview_audio_role' => StudyCardAudioRole::Answer,
        ]);

        $updated = app(UpdateStudyCardDraftAction::class)->handle($draft, UpdateStudyCardDraftData::fromInput(
            hasPreviewAudio: true,
            previewAudioJson: null,
        ));

        $updated->refresh();

        $this->assertNull($updated->preview_audio_json);
        $this->assertNull($updated->preview_audio_role);
    }

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

    public function test_it_rejects_generating_draft_edits(): void
    {
        $draft = StudyCardDraft::factory()->create();

        $this->expectException(StudyCardDraftConflictException::class);
        $this->expectExceptionMessage('Generating drafts cannot be edited yet.');

        app(UpdateStudyCardDraftAction::class)->handle($draft, UpdateStudyCardDraftData::fromInput(
            hasPrompt: true,
            promptJson: ['cueText' => '会社'],
            hasAnswer: true,
            answerJson: ['meaning' => 'company'],
        ));
    }

    public function test_it_rechecks_the_locked_draft_status_before_saving(): void
    {
        $draft = StudyCardDraft::factory()->ready()->create();

        StudyCardDraft::query()
            ->whereKey($draft->id)
            ->update(['status' => StudyManualCardDraftStatus::Generating->value]);

        $this->expectException(StudyCardDraftConflictException::class);
        $this->expectExceptionMessage('Generating drafts cannot be edited yet.');

        app(UpdateStudyCardDraftAction::class)->handle($draft, UpdateStudyCardDraftData::fromInput(
            hasPrompt: true,
            promptJson: ['cueText' => '会社'],
            hasAnswer: true,
            answerJson: ['meaning' => 'company'],
        ));
    }

    public function test_it_rejects_drafts_deleted_before_the_locked_write(): void
    {
        $draft = StudyCardDraft::factory()->ready()->create();
        $draft->delete();

        $this->expectException(StudyCardDraftNotFoundException::class);
        $this->expectExceptionMessage('Study card draft not found.');

        app(UpdateStudyCardDraftAction::class)->handle($draft, UpdateStudyCardDraftData::fromInput(
            hasPrompt: true,
            promptJson: ['cueText' => '会社'],
            hasAnswer: true,
            answerJson: ['meaning' => 'company'],
        ));
    }

    public function test_it_rejects_drafts_deleted_before_empty_autosave_readback(): void
    {
        $draft = StudyCardDraft::factory()->ready()->create();
        $draft->delete();

        $this->expectException(StudyCardDraftNotFoundException::class);
        $this->expectExceptionMessage('Study card draft not found.');

        app(UpdateStudyCardDraftAction::class)->handle($draft, UpdateStudyCardDraftData::fromInput());
    }

    public function test_it_rejects_mismatched_payload_presence_for_direct_callers(): void
    {
        $this->expectException(StudyCardDraftValidationException::class);
        $this->expectExceptionMessage('study card payloads contain invalid content.');

        UpdateStudyCardDraftData::fromInput(
            hasPrompt: true,
            promptJson: ['cueText' => '会社'],
        );
    }

    public function test_it_rejects_null_present_payloads_for_direct_callers(): void
    {
        $this->expectException(StudyCardDraftValidationException::class);
        $this->expectExceptionMessage('study card payloads contain invalid content.');

        UpdateStudyCardDraftData::fromInput(
            hasPrompt: true,
            promptJson: null,
            hasAnswer: true,
            answerJson: ['meaning' => 'company'],
        );
    }

    public function test_it_rejects_oversized_image_prompts_for_direct_callers(): void
    {
        $this->expectException(StudyCardDraftValidationException::class);
        $this->expectExceptionMessage('imagePrompt must be 1000 characters or fewer.');

        UpdateStudyCardDraftData::fromInput(
            hasImagePrompt: true,
            imagePrompt: str_repeat('a', 1001),
        );
    }

    public function test_it_rejects_oversized_variant_ids_for_direct_callers(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Study variant IDs must be 64 characters or fewer.');

        UpdateStudyCardDraftData::fromInput(
            hasVariantGroupId: true,
            variantGroupId: str_repeat('a', 65),
        );
    }

    public function test_it_rejects_oversized_variant_sentence_ids_for_direct_callers(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Study variant IDs must be 64 characters or fewer.');

        UpdateStudyCardDraftData::fromInput(
            hasVariantSentenceId: true,
            variantSentenceId: str_repeat('b', 65),
        );
    }

    public function test_it_rejects_invalid_variant_stage_for_direct_callers(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Study variant stage must be between 1 and 65535.');

        UpdateStudyCardDraftData::fromInput(
            hasVariantStage: true,
            variantStage: 0,
        );
    }

    public function test_it_rejects_invalid_variant_enums_for_direct_callers(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Variant kind must be one of:');

        UpdateStudyCardDraftData::fromInput(
            hasVariantKind: true,
            variantKind: 'sentence-audio-recognition',
        );
    }

    public function test_it_rejects_invalid_variant_status_for_direct_callers(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Variant status must be one of:');

        UpdateStudyCardDraftData::fromInput(
            hasVariantStatus: true,
            variantStatus: 'not-a-status',
        );
    }

    public function test_it_rejects_deep_payloads_for_direct_callers(): void
    {
        $this->expectException(StudyCardDraftValidationException::class);
        $this->expectExceptionMessage('prompt must be 8 levels deep or fewer.');

        UpdateStudyCardDraftData::fromInput(
            hasPrompt: true,
            promptJson: ['a' => ['b' => ['c' => ['d' => ['e' => ['f' => ['g' => ['h' => ['i' => 'deep']]]]]]]]],
            hasAnswer: true,
            answerJson: ['meaning' => 'company'],
        );
    }

    public function test_it_rejects_invalid_preview_media_kind_for_direct_callers(): void
    {
        $this->expectException(StudyCardDraftValidationException::class);
        $this->expectExceptionMessage('study card payloads contain invalid content.');

        UpdateStudyCardDraftData::fromInput(
            hasPreviewAudio: true,
            previewAudioJson: [
                'filename' => 'wrong.webp',
                'mediaKind' => 'image',
                'source' => 'generated',
            ],
        );
    }

    public function test_it_rejects_malformed_preview_media_refs_for_direct_callers(): void
    {
        $this->expectException(StudyCardDraftValidationException::class);
        $this->expectExceptionMessage('study card payloads contain invalid content.');

        UpdateStudyCardDraftData::fromInput(
            hasPreviewImage: true,
            previewImageJson: [
                'filename' => '',
                'mediaKind' => 'image',
                'source' => 'external',
                'extra' => 'unexpected',
            ],
        );
    }
}
