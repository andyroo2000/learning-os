<?php

namespace App\Domain\Study\Data;

use App\Domain\Flashcards\Enums\CardType;
use App\Domain\Study\Enums\StudyCardCreationKind;
use App\Domain\Study\Enums\StudyCardImagePlacement;
use App\Domain\Study\Exceptions\StudyCardDraftValidationException;
use App\Domain\Study\Models\StudyCardDraft;
use App\Domain\Study\Support\HasCardBookSource;
use App\Domain\Study\Support\HasLearnSoonPreference;
use App\Domain\Study\Support\StudyCardPayloadShapeValidator;
use App\Domain\Vocabulary\Enums\VocabVariantKind;
use App\Domain\Vocabulary\Enums\VocabVariantStatus;
use App\Domain\Vocabulary\Support\VocabVariantMetadataInput;
use App\Support\Identifiers\CanonicalUlid;
use DateTimeInterface;
use LogicException;

final class CreateStudyCardDraftData
{
    use HasCardBookSource;
    use HasLearnSoonPreference;

    public const MAX_IMAGE_PROMPT_LENGTH = StudyCardDraft::MAX_IMAGE_PROMPT_LENGTH;

    private function __construct(
        public readonly int $userId,
        public readonly StudyCardCreationKind $creationKind,
        public readonly CardType $cardType,
        public readonly array $promptJson,
        public readonly array $answerJson,
        public readonly StudyCardImagePlacement $imagePlacement,
        public readonly ?string $imagePrompt,
        public readonly ?string $variantGroupId,
        public readonly ?string $variantSentenceId,
        public readonly ?VocabVariantKind $variantKind,
        public readonly ?int $variantStage,
        public readonly ?VocabVariantStatus $variantStatus,
        public readonly ?DateTimeInterface $variantUnlockedAt,
        public readonly ?string $id,
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
        VocabVariantKind|string|null $variantKind = null,
        ?int $variantStage = null,
        VocabVariantStatus|string|null $variantStatus = null,
        ?DateTimeInterface $variantUnlockedAt = null,
        ?string $id = null,
    ): self {
        if ($userId < 1) {
            throw new LogicException('Study card draft user ID must be a positive integer.');
        }

        self::validatePayloadShape($promptJson, $answerJson);
        VocabVariantMetadataInput::assertValidStage(
            $variantStage,
            'Study variant stage must be between 1 and 65535.',
        );

        return new self(
            userId: $userId,
            creationKind: self::creationKindFromInput($creationKind),
            cardType: CardType::fromInput($cardType),
            promptJson: $promptJson,
            answerJson: $answerJson,
            imagePlacement: self::imagePlacementFromInput($imagePlacement),
            imagePrompt: self::nullableTrimmedString($imagePrompt),
            variantGroupId: VocabVariantMetadataInput::nullableId(
                $variantGroupId,
                'Study variant IDs must be 64 characters or fewer.',
            ),
            variantSentenceId: VocabVariantMetadataInput::nullableId(
                $variantSentenceId,
                'Study variant IDs must be 64 characters or fewer.',
            ),
            variantKind: VocabVariantMetadataInput::kindFromInput($variantKind),
            variantStage: $variantStage,
            variantStatus: VocabVariantMetadataInput::statusFromInput($variantStatus),
            variantUnlockedAt: VocabVariantMetadataInput::normalizedTimestamp($variantUnlockedAt),
            // HTTP normalizes this too; repeat canonicalization for direct callers.
            id: $id === null ? null : CanonicalUlid::normalize($id),
        );
    }

    private static function creationKindFromInput(StudyCardCreationKind|string $creationKind): StudyCardCreationKind
    {
        if ($creationKind instanceof StudyCardCreationKind) {
            return $creationKind;
        }

        return StudyCardCreationKind::tryFrom(strtolower(trim($creationKind)))
            ?? throw StudyCardDraftValidationException::invalidCreationKind();
    }

    private static function imagePlacementFromInput(StudyCardImagePlacement|string|null $imagePlacement): StudyCardImagePlacement
    {
        if ($imagePlacement instanceof StudyCardImagePlacement) {
            return $imagePlacement;
        }

        if ($imagePlacement === null) {
            return StudyCardImagePlacement::None;
        }

        return StudyCardImagePlacement::tryFrom(strtolower(trim($imagePlacement)))
            ?? throw StudyCardDraftValidationException::invalidImagePlacement();
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
