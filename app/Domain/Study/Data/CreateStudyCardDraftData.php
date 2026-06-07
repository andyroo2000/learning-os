<?php

namespace App\Domain\Study\Data;

use App\Domain\Flashcards\Enums\CardType;
use App\Domain\Study\Enums\StudyCardCreationKind;
use App\Domain\Study\Enums\StudyCardImagePlacement;
use App\Domain\Study\Enums\StudyVocabVariantKind;
use App\Domain\Study\Enums\StudyVocabVariantStatus;
use App\Domain\Study\Exceptions\StudyCardDraftValidationException;
use App\Domain\Study\Models\StudyCardDraft;
use App\Domain\Study\Support\StudyCardPayloadShapeValidator;
use Carbon\CarbonImmutable;
use DateTimeInterface;
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
        public ?string $variantGroupId,
        public ?string $variantSentenceId,
        public ?StudyVocabVariantKind $variantKind,
        public ?int $variantStage,
        public ?StudyVocabVariantStatus $variantStatus,
        public ?DateTimeInterface $variantUnlockedAt,
    ) {}

    public static function fromInput(
        int $userId,
        StudyCardCreationKind|string $creationKind,
        CardType|string $cardType,
        array $promptJson,
        array $answerJson,
        StudyCardImagePlacement|string|null $imagePlacement = null,
        ?string $imagePrompt = null,
        ?string $variantGroupId = null,
        ?string $variantSentenceId = null,
        StudyVocabVariantKind|string|null $variantKind = null,
        ?int $variantStage = null,
        StudyVocabVariantStatus|string|null $variantStatus = null,
        ?DateTimeInterface $variantUnlockedAt = null,
    ): self {
        if ($userId < 1) {
            throw new LogicException('Study card draft user ID must be a positive integer.');
        }

        self::validatePayloadShape($promptJson, $answerJson);
        self::validateVariantStage($variantStage);

        return new self(
            userId: $userId,
            creationKind: self::creationKindFromInput($creationKind),
            cardType: CardType::fromInput($cardType),
            promptJson: $promptJson,
            answerJson: $answerJson,
            imagePlacement: self::imagePlacementFromInput($imagePlacement),
            imagePrompt: self::nullableTrimmedString($imagePrompt),
            variantGroupId: self::nullableVariantId($variantGroupId),
            variantSentenceId: self::nullableVariantId($variantSentenceId),
            variantKind: self::variantKindFromInput($variantKind),
            variantStage: $variantStage,
            variantStatus: self::variantStatusFromInput($variantStatus),
            variantUnlockedAt: self::normalizedTimestamp($variantUnlockedAt),
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

    private static function variantKindFromInput(StudyVocabVariantKind|string|null $variantKind): ?StudyVocabVariantKind
    {
        if ($variantKind instanceof StudyVocabVariantKind || $variantKind === null) {
            return $variantKind;
        }

        return StudyVocabVariantKind::from(strtolower(trim($variantKind)));
    }

    private static function variantStatusFromInput(StudyVocabVariantStatus|string|null $variantStatus): ?StudyVocabVariantStatus
    {
        if ($variantStatus instanceof StudyVocabVariantStatus || $variantStatus === null) {
            return $variantStatus;
        }

        return StudyVocabVariantStatus::from(strtolower(trim($variantStatus)));
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

    private static function nullableVariantId(?string $value): ?string
    {
        $trimmed = self::nullableTrimmedString($value);

        if ($trimmed === null) {
            return null;
        }

        if (mb_strlen($trimmed, 'UTF-8') > StudyCardDraft::MAX_VARIANT_ID_LENGTH) {
            throw new LogicException('Study variant IDs must be 64 characters or fewer.');
        }

        return $trimmed;
    }

    private static function validateVariantStage(?int $variantStage): void
    {
        if ($variantStage === null) {
            return;
        }

        if ($variantStage < 1 || $variantStage > StudyCardDraft::MAX_VARIANT_STAGE) {
            throw new LogicException('Study variant stage must be between 1 and 65535.');
        }
    }

    private static function normalizedTimestamp(?DateTimeInterface $value): ?DateTimeInterface
    {
        return $value === null ? null : CarbonImmutable::instance($value)->startOfSecond();
    }

    private static function validatePayloadShape(array $promptJson, array $answerJson): void
    {
        StudyCardPayloadShapeValidator::assertDraftPayloadsAreValid($promptJson, $answerJson);
    }
}
