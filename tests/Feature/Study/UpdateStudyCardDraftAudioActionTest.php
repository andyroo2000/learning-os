<?php

namespace Tests\Feature\Study;

use App\Domain\Study\Actions\UpdateStudyCardDraftAction;
use App\Domain\Study\Data\UpdateStudyCardDraftData;
use App\Domain\Study\Enums\StudyCardAudioRole;
use App\Domain\Study\Exceptions\StudyCardDraftValidationException;
use App\Domain\Study\Models\StudyCardDraft;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UpdateStudyCardDraftAudioActionTest extends TestCase
{
    use RefreshDatabase;

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
}
