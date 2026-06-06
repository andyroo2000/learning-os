<?php

namespace App\Domain\Study\Data;

use App\Domain\Flashcards\Enums\CardType;
use App\Domain\Study\Enums\StudyCardCreationKind;
use App\Domain\Study\Enums\StudyCardImagePlacement;
use App\Domain\Study\Exceptions\StudyCardDraftValidationException;
use App\Domain\Study\Models\StudyCardDraft;
use App\Domain\Study\Support\StudyCardPayloadShapeValidator;
use LogicException;

final readonly class CreateStudyCardDraftData
{
    public const MAX_IMAGE_PROMPT_LENGTH = StudyCardDraft::MAX_IMAGE_PROMPT_LENGTH;

    private function __construct(
        public int $userId,
        public StudyCardCreationKind $creationKind,
        public CardType $cardType,
        public array $promptJson,
        public array $answerJson,
        public StudyCardImagePlacement $imagePlacement,
        public ?string $imagePrompt,
    ) {}

    public static function fromInput(
        int $userId,
        StudyCardCreationKind|string $creationKind,
        CardType|string $cardType,
        array $promptJson,
        array $answerJson,
        StudyCardImagePlacement|string|null $imagePlacement = null,
        ?string $imagePrompt = null,
    ): self {
        if ($userId < 1) {
            throw new LogicException('Study card draft user ID must be a positive integer.');
        }

        self::validatePayloadShape($promptJson, $answerJson);

        return new self(
            userId: $userId,
            creationKind: self::creationKindFromInput($creationKind),
            cardType: CardType::fromInput($cardType),
            promptJson: $promptJson,
            answerJson: $answerJson,
            imagePlacement: self::imagePlacementFromInput($imagePlacement),
            imagePrompt: self::nullableTrimmedString($imagePrompt),
        );
    }

    private static function creationKindFromInput(StudyCardCreationKind|string $creationKind): StudyCardCreationKind
    {
        if ($creationKind instanceof StudyCardCreationKind) {
            return $creationKind;
        }

        return StudyCardCreationKind::from(strtolower(trim($creationKind)));
    }

    private static function imagePlacementFromInput(StudyCardImagePlacement|string|null $imagePlacement): StudyCardImagePlacement
    {
        if ($imagePlacement instanceof StudyCardImagePlacement) {
            return $imagePlacement;
        }

        if ($imagePlacement === null) {
            return StudyCardImagePlacement::None;
        }

        return StudyCardImagePlacement::from(strtolower(trim($imagePlacement)));
    }

    private static function nullableTrimmedString(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        if ($trimmed === '') {
            return null;
        }

        // StoreStudyCardDraftRequest enforces the HTTP boundary; repeat the guard here
        // so direct action callers cannot bypass the ConvoLab-compatible limit.
        if (mb_strlen($trimmed, 'UTF-8') > self::MAX_IMAGE_PROMPT_LENGTH) {
            throw StudyCardDraftValidationException::imagePromptTooLong(self::MAX_IMAGE_PROMPT_LENGTH);
        }

        return $trimmed;
    }

    private static function validatePayloadShape(array $promptJson, array $answerJson): void
    {
        StudyCardPayloadShapeValidator::assertDraftPayloadsAreValid($promptJson, $answerJson);
    }
}
