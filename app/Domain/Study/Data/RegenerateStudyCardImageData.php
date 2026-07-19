<?php

namespace App\Domain\Study\Data;

use App\Domain\Study\Enums\StudyCardImagePlacement;
use App\Domain\Study\Exceptions\StudyCardImageValidationException;
use App\Domain\Study\Models\StudyCardDraft;

final readonly class RegenerateStudyCardImageData
{
    private function __construct(
        public string $imagePrompt,
        public StudyCardImagePlacement $imagePlacement,
    ) {}

    public static function fromInput(
        string $imagePrompt,
        StudyCardImagePlacement|string $imagePlacement,
    ): self {
        $imagePrompt = trim($imagePrompt);
        if ($imagePrompt === '') {
            throw StudyCardImageValidationException::missingPrompt();
        }

        if (mb_strlen($imagePrompt) > StudyCardDraft::MAX_IMAGE_PROMPT_LENGTH) {
            throw StudyCardImageValidationException::promptTooLong(
                StudyCardDraft::MAX_IMAGE_PROMPT_LENGTH,
            );
        }

        if (is_string($imagePlacement)) {
            $imagePlacement = StudyCardImagePlacement::tryFrom(strtolower(trim($imagePlacement)))
                ?? throw StudyCardImageValidationException::invalidRole();
        }

        if ($imagePlacement === StudyCardImagePlacement::None) {
            throw StudyCardImageValidationException::invalidRole();
        }

        return new self(
            imagePrompt: $imagePrompt,
            imagePlacement: $imagePlacement,
        );
    }
}
