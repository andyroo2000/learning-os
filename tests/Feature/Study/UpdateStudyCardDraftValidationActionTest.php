<?php

namespace Tests\Feature\Study;

use App\Domain\Study\Data\UpdateStudyCardDraftData;
use App\Domain\Study\Enums\StudyCardAudioRole;
use App\Domain\Study\Enums\StudyCardImagePlacement;
use App\Domain\Study\Exceptions\StudyCardDraftValidationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UpdateStudyCardDraftValidationActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_rejects_mismatched_payload_presence_for_direct_callers(): void
    {
        $this->expectInvalidPayloadContent();

        UpdateStudyCardDraftData::fromInput(
            hasPrompt: true,
            promptJson: ['cueText' => '会社'],
        );
    }

    public function test_it_rejects_null_present_payloads_for_direct_callers(): void
    {
        $this->expectInvalidPayloadContent();

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

    public function test_it_rejects_invalid_image_placements_for_direct_callers_with_domain_validation(): void
    {
        $this->expectException(StudyCardDraftValidationException::class);
        $this->expectExceptionMessage('imagePlacement must be one of: '.implode(', ', StudyCardImagePlacement::values()).'.');

        UpdateStudyCardDraftData::fromInput(
            hasImagePlacement: true,
            imagePlacement: 'sideways',
        );
    }

    public function test_it_rejects_blank_image_placements_for_direct_callers_with_domain_validation(): void
    {
        $this->expectException(StudyCardDraftValidationException::class);
        $this->expectExceptionMessage('imagePlacement must be one of: '.implode(', ', StudyCardImagePlacement::values()).'.');

        UpdateStudyCardDraftData::fromInput(
            hasImagePlacement: true,
            imagePlacement: '   ',
        );
    }

    public function test_it_rejects_invalid_preview_audio_roles_for_direct_callers_with_domain_validation(): void
    {
        $this->expectException(StudyCardDraftValidationException::class);
        $this->expectExceptionMessage('previewAudioRole must be one of: '.implode(', ', StudyCardAudioRole::values()).'.');

        UpdateStudyCardDraftData::fromInput(
            hasPreviewAudioRole: true,
            previewAudioRole: 'front',
        );
    }

    public function test_it_rejects_blank_preview_audio_roles_for_direct_callers_with_domain_validation(): void
    {
        $this->expectException(StudyCardDraftValidationException::class);
        $this->expectExceptionMessage('previewAudioRole must be one of: '.implode(', ', StudyCardAudioRole::values()).'.');

        UpdateStudyCardDraftData::fromInput(
            hasPreviewAudioRole: true,
            previewAudioRole: '   ',
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

    public function test_it_rejects_wrong_types_for_owned_payload_fields_for_direct_callers(): void
    {
        try {
            UpdateStudyCardDraftData::fromInput(
                hasPrompt: true,
                promptJson: ['cueText' => '会社'],
                hasAnswer: true,
                answerJson: ['meaning' => ['not text']],
            );

            $this->fail('Expected a payload validation exception.');
        } catch (StudyCardDraftValidationException $e) {
            $this->assertSame('answer.meaning', $e->field());
            $this->assertSame('answer.meaning must be a string or null.', $e->getMessage());
        }
    }

    public function test_it_rejects_invalid_preview_media_kind_for_direct_callers(): void
    {
        $this->assertInvalidPreviewMediaRejected('audio');
    }

    public function test_it_rejects_malformed_preview_media_refs_for_direct_callers(): void
    {
        $this->assertInvalidPreviewMediaRejected('image');
    }

    private function expectInvalidPayloadContent(): void
    {
        $this->expectException(StudyCardDraftValidationException::class);
        $this->expectExceptionMessage('study card payloads contain invalid content.');
    }

    private function assertInvalidPreviewMediaRejected(string $kind): void
    {
        $this->expectInvalidPayloadContent();

        $kind === 'audio'
            ? UpdateStudyCardDraftData::fromInput(
                hasPreviewAudio: true,
                previewAudioJson: [
                    'filename' => 'wrong.webp',
                    'mediaKind' => 'image',
                    'source' => 'generated',
                ],
            )
            : UpdateStudyCardDraftData::fromInput(
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
