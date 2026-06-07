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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UpdateStudyCardDraftActionTest extends TestCase
{
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
        ));

        $updated->refresh();

        $this->assertSame(['cueText' => '会議'], $updated->prompt_json);
        $this->assertSame(['expression' => '会議', 'meaning' => 'meeting'], $updated->answer_json);
        $this->assertSame(StudyCardImagePlacement::Answer, $updated->image_placement);
        $this->assertSame('A quiet meeting room', $updated->image_prompt);
        $this->assertSame('audio-1', $updated->preview_audio_json['id']);
        $this->assertSame(StudyCardAudioRole::Prompt, $updated->preview_audio_role);
        $this->assertSame('image-1', $updated->preview_image_json['id']);
        $this->assertSame(StudyManualCardDraftStatus::Ready, $updated->status);
        $this->assertSame('Old error.', $updated->error_message);

        $entry = SyncFeedEntry::query()->sole();
        $this->assertSame('study', $entry->domain);
        $this->assertSame('study_card_draft', $entry->resource_type);
        $this->assertSame($draft->id, $entry->resource_id);
        $this->assertSame(SyncFeedOperation::Update, $entry->operation);
        $this->assertSame(['cueText' => '会議'], $entry->payload['prompt_json']);
        $this->assertSame('A quiet meeting room', $entry->payload['image_prompt']);
        $this->assertSame('audio-1', $entry->payload['preview_audio_json']['id']);
        $this->assertSame(StudyCardAudioRole::Prompt->value, $entry->payload['preview_audio_role']);
        $this->assertNull($entry->payload['committed_card_id']);
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
        ));

        $updated->refresh();

        $this->assertSame('Keep this', $updated->image_prompt);
        $this->assertSame('keep.mp3', $updated->preview_audio_json['filename']);
        $this->assertSame('keep.webp', $updated->preview_image_json['filename']);
    }

    public function test_it_does_not_record_sync_entries_for_noop_resubmits(): void
    {
        $draft = StudyCardDraft::factory()->ready()->create([
            'prompt_json' => ['cueText' => '会社'],
            'answer_json' => ['expression' => '会社', 'meaning' => 'company'],
            'image_placement' => StudyCardImagePlacement::Both,
            'image_prompt' => 'Keep this',
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
        ));

        $updated->refresh();

        $this->assertSame(StudyCardImagePlacement::None, $updated->image_placement);
        $this->assertNull($updated->image_prompt);
        $this->assertNull($updated->preview_audio_json);
        $this->assertNull($updated->preview_audio_role);
        $this->assertNull($updated->preview_image_json);
        $this->assertSame(1, SyncFeedEntry::query()->count());
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
