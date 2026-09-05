<?php

namespace Tests\Feature\Study;

use App\Domain\Study\Enums\StudyCardAudioRole;
use App\Domain\Study\Models\StudyCardDraft;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UpdateStudyCardDraftValidationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_validates_autosave_payloads_and_preview_media(): void
    {
        $user = $this->signIn();
        $draft = StudyCardDraft::factory()->failed()->for($user)->create();

        $this->assertPayloadAndAudioValidation($draft, $user);
        $this->assertPayloadShapeValidation($draft);
        $this->assertPreviewMediaValidation($draft);
        $this->assertVariantMetadataValidation($draft);
    }

    private function assertPayloadAndAudioValidation(StudyCardDraft $draft, User $user): void
    {
        $this->patchJson("/api/study/card-drafts/{$draft->id}", [
            'prompt' => ['cueText' => '会社'],
            'previewAudioRole' => 'front',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['prompt', 'answer', 'previewAudioRole'])
            ->assertJsonPath('errors.prompt.0', 'prompt and answer payloads are required.')
            ->assertJsonPath('errors.previewAudioRole.0', 'previewAudioRole must be prompt or answer.');

        $this->patchJson("/api/study/card-drafts/{$draft->id}", [
            'previewAudioRole' => 'prompt',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['previewAudioRole'])
            ->assertJsonPath('errors.previewAudioRole.0', 'previewAudioRole requires previewAudio.');

        $this->patchJson("/api/study/card-drafts/{$draft->id}", [
            'previewAudio' => [
                'filename' => 'wrong.webp',
                'mediaKind' => 'image',
                'source' => 'generated',
            ],
            'previewAudioRole' => 'prompt',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['previewAudio.mediaKind', 'previewAudioRole'])
            ->assertJsonPath('errors.previewAudioRole.0', 'previewAudioRole requires previewAudio.');

        $audioDraft = StudyCardDraft::factory()->ready()->for($user)->create([
            'preview_audio_json' => [
                'filename' => 'keep.mp3',
                'mediaKind' => 'audio',
                'source' => 'generated',
            ],
            'preview_audio_role' => StudyCardAudioRole::Answer,
        ]);

        $this->patchJson("/api/study/card-drafts/{$audioDraft->id}", [
            'previewAudio' => null,
            'previewAudioRole' => 'prompt',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['previewAudioRole'])
            ->assertJsonPath('errors.previewAudioRole.0', 'previewAudioRole requires previewAudio.');

        $audioDraft->refresh();
        $this->assertSame('keep.mp3', $audioDraft->preview_audio_json['filename']);
        $this->assertSame(StudyCardAudioRole::Answer, $audioDraft->preview_audio_role);
    }

    private function assertPayloadShapeValidation(StudyCardDraft $draft): void
    {
        $this->patchJson("/api/study/card-drafts/{$draft->id}", [
            'prompt' => [['cueText' => '会社']],
            'answer' => ['meaning' => 'company'],
            'imagePlacement' => 'sideways',
            'imagePrompt' => str_repeat('a', 1001),
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['prompt', 'imagePlacement', 'imagePrompt'])
            ->assertJsonPath('errors.prompt.0', 'prompt must be an object.')
            ->assertJsonCount(1, 'errors.prompt')
            ->assertJsonPath('errors.imagePlacement.0', 'imagePlacement must be none, prompt, answer, or both.')
            ->assertJsonPath('errors.imagePrompt.0', 'imagePrompt must be 1000 characters or fewer.');

        $this->patchJson("/api/study/card-drafts/{$draft->id}", [
            'prompt' => ['cueText' => str_repeat('a', 25 * 1024)],
            'answer' => [],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['payloads'])
            ->assertJsonPath('errors.payloads.0', 'study card payloads must be 24 KB or smaller.');
    }

    private function assertPreviewMediaValidation(StudyCardDraft $draft): void
    {
        $this->patchJson("/api/study/card-drafts/{$draft->id}", [
            'previewAudio' => [
                'filename' => 'image.webp',
                'mediaKind' => 'image',
                'source' => 'generated',
            ],
            'previewImage' => [
                'filename' => 'audio.mp3',
                'mediaKind' => 'audio',
                'source' => 'generated',
            ],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['previewAudio.mediaKind', 'previewImage.mediaKind'])
            ->assertJsonFragment([
                'previewAudio.mediaKind' => ['draft.previewAudio.mediaKind must be audio.'],
                'previewImage.mediaKind' => ['draft.previewImage.mediaKind must be image.'],
            ]);

        $this->patchJson("/api/study/card-drafts/{$draft->id}", [
            'previewAudio' => [
                'mediaKind' => 'audio',
            ],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['previewAudio.filename', 'previewAudio.source'])
            ->assertJsonFragment([
                'previewAudio.filename' => ['draft.previewAudio.filename is required.'],
                'previewAudio.source' => ['draft media source must be imported, generated, missing, imported_image, or imported_other.'],
            ]);

        $this->patchJson("/api/study/card-drafts/{$draft->id}", [
            'previewImage' => [
                'filename' => 'image.webp',
                'source' => 'external',
            ],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['previewImage.mediaKind', 'previewImage.source'])
            ->assertJsonFragment([
                'previewImage.mediaKind' => ['draft.previewImage.mediaKind must be image.'],
                'previewImage.source' => ['draft media source must be imported, generated, missing, imported_image, or imported_other.'],
            ]);
    }

    private function assertVariantMetadataValidation(StudyCardDraft $draft): void
    {
        $this->patchJson("/api/study/card-drafts/{$draft->id}", [
            'variantGroupId' => str_repeat('a', 65),
            'variantSentenceId' => ['sentence-1'],
            'variantKind' => 'sentence-audio-recognition',
            'variantStage' => 0,
            'variantStatus' => ['available'],
            'variantUnlockedAt' => 'yesterday',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'variantGroupId',
                'variantSentenceId',
                'variantKind',
                'variantStage',
                'variantStatus',
                'variantUnlockedAt',
            ])
            ->assertJsonPath('errors.variantGroupId.0', 'variantGroupId must be 64 characters or fewer.')
            ->assertJsonPath('errors.variantSentenceId.0', 'variantSentenceId must be a string.')
            ->assertJsonPath('errors.variantKind.0', 'variantKind is not supported.')
            ->assertJsonPath('errors.variantStage.0', 'variantStage must be between 1 and 65535.')
            ->assertJsonPath('errors.variantStatus.0', 'variantStatus must be a string.')
            ->assertJsonPath('errors.variantUnlockedAt.0', 'variantUnlockedAt must be a valid timestamp.');

        foreach ([
            '2026-02-31T14:15:30',
            '2026-06-04T14:15:30+15:00',
            '2026-06-04T14:15:30-13:00',
        ] as $variantUnlockedAt) {
            $this->patchJson("/api/study/card-drafts/{$draft->id}", [
                'variantUnlockedAt' => $variantUnlockedAt,
            ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['variantUnlockedAt'])
                ->assertJsonPath('errors.variantUnlockedAt.0', 'variantUnlockedAt must be a valid timestamp.');
        }
    }
}
