<?php

namespace App\Domain\Study\Actions;

use App\Domain\Study\Data\UpdateStudyCardDraftData;
use App\Domain\Study\Enums\StudyCardImagePlacement;
use App\Domain\Study\Enums\StudyManualCardDraftStatus;
use App\Domain\Study\Exceptions\StudyCardDraftConflictException;
use App\Domain\Study\Exceptions\StudyCardDraftValidationException;
use App\Domain\Study\Models\StudyCardDraft;
use App\Domain\Study\Services\OpenAiStudyImageGenerator;
use Throwable;

class GenerateStudyCardDraftPreviewImageAction
{
    public function __construct(
        private readonly OpenAiStudyImageGenerator $openAiImage,
        private readonly PersistGeneratedStudyMediaAction $persistGeneratedMedia,
        private readonly UpdateStudyCardDraftAction $updateDraft,
        private readonly DiscardGeneratedStudyMediaAction $discardGeneratedMedia,
    ) {}

    public function handle(StudyCardDraft $draft): StudyCardDraft
    {
        if ($draft->status === StudyManualCardDraftStatus::Generating) {
            throw StudyCardDraftConflictException::generatingCannotBeEdited();
        }

        if ($draft->image_placement === StudyCardImagePlacement::None) {
            throw StudyCardDraftValidationException::previewImageRequiresPlacement();
        }

        $imagePrompt = is_string($draft->image_prompt) ? trim($draft->image_prompt) : '';
        if ($imagePrompt === '') {
            throw StudyCardDraftValidationException::missingPreviewImagePrompt();
        }

        $generated = $this->persistGeneratedMedia->handle(
            userId: $draft->user_id,
            bytes: $this->openAiImage->generate($imagePrompt),
            mediaKind: 'image',
            mimeType: 'image/webp',
            extension: 'webp',
        );

        try {
            return $this->updateDraft->handle($draft, UpdateStudyCardDraftData::fromInput(
                hasPreviewImage: true,
                previewImageJson: $generated->mediaRef,
            ));
        } catch (Throwable $exception) {
            $this->discardGeneratedMedia->handle($generated->mediaAsset);

            throw $exception;
        }
    }
}
