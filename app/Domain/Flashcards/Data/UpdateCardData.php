<?php

namespace App\Domain\Flashcards\Data;

use App\Domain\Flashcards\Enums\CardType;
use App\Domain\Vocabulary\Enums\VocabVariantKind;
use App\Domain\Vocabulary\Enums\VocabVariantStatus;
use App\Domain\Vocabulary\Support\VocabVariantMetadataInput;
use DateTimeInterface;

/**
 * Cards persist variant metadata as scalar storage for Vocabulary study variants.
 * The DTO keeps the direct-caller contract aligned with create-card and study-draft paths.
 */
final readonly class UpdateCardData
{
    private function __construct(
        public string $frontText,
        public string $backText,
        public ?CardType $cardType,
        public bool $hasPromptJson,
        public ?array $promptJson,
        public bool $hasAnswerJson,
        public ?array $answerJson,
        public bool $hasVariantGroupId,
        public ?string $variantGroupId,
        public bool $hasVariantSentenceId,
        public ?string $variantSentenceId,
        public bool $hasVariantKind,
        public ?VocabVariantKind $variantKind,
        public bool $hasVariantStage,
        public ?int $variantStage,
        public bool $hasVariantStatus,
        public ?VocabVariantStatus $variantStatus,
        public bool $hasVariantUnlockedAt,
        public ?DateTimeInterface $variantUnlockedAt,
    ) {}

    public static function fromInput(
        string $frontText,
        string $backText,
        CardType|string|null $cardType = null,
        bool $hasPromptJson = false,
        ?array $promptJson = null,
        bool $hasAnswerJson = false,
        ?array $answerJson = null,
        bool $hasVariantGroupId = false,
        ?string $variantGroupId = null,
        bool $hasVariantSentenceId = false,
        ?string $variantSentenceId = null,
        bool $hasVariantKind = false,
        VocabVariantKind|string|null $variantKind = null,
        bool $hasVariantStage = false,
        ?int $variantStage = null,
        bool $hasVariantStatus = false,
        VocabVariantStatus|string|null $variantStatus = null,
        bool $hasVariantUnlockedAt = false,
        ?DateTimeInterface $variantUnlockedAt = null,
    ): self {
        if ($hasVariantStage) {
            VocabVariantMetadataInput::assertValidStage(
                $variantStage,
                'Card variant stage must be between 1 and 65535.',
            );
        }

        // Normalize here too so non-HTTP callers get the same domain invariants.
        return new self(
            frontText: trim($frontText),
            backText: trim($backText),
            cardType: $cardType === null ? null : CardType::fromInput($cardType),
            hasPromptJson: $hasPromptJson,
            promptJson: $promptJson,
            hasAnswerJson: $hasAnswerJson,
            answerJson: $answerJson,
            hasVariantGroupId: $hasVariantGroupId,
            variantGroupId: $hasVariantGroupId ? VocabVariantMetadataInput::nullableId(
                $variantGroupId,
                'Card variant IDs must be 64 characters or fewer.',
            ) : null,
            hasVariantSentenceId: $hasVariantSentenceId,
            variantSentenceId: $hasVariantSentenceId ? VocabVariantMetadataInput::nullableId(
                $variantSentenceId,
                'Card variant IDs must be 64 characters or fewer.',
            ) : null,
            hasVariantKind: $hasVariantKind,
            variantKind: $hasVariantKind ? VocabVariantMetadataInput::kindFromInput($variantKind) : null,
            hasVariantStage: $hasVariantStage,
            variantStage: $hasVariantStage ? $variantStage : null,
            hasVariantStatus: $hasVariantStatus,
            variantStatus: $hasVariantStatus ? VocabVariantMetadataInput::statusFromInput($variantStatus) : null,
            hasVariantUnlockedAt: $hasVariantUnlockedAt,
            variantUnlockedAt: $hasVariantUnlockedAt ? VocabVariantMetadataInput::normalizedTimestamp($variantUnlockedAt) : null,
        );
    }
}
