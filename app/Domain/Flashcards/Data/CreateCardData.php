<?php

namespace App\Domain\Flashcards\Data;

use App\Domain\Flashcards\Enums\CardSelectionPolicy;
use App\Domain\Flashcards\Enums\CardType;
use App\Domain\Study\Support\HasCardBookSource;
use App\Domain\Vocabulary\Enums\VocabVariantKind;
use App\Domain\Vocabulary\Enums\VocabVariantStatus;
use App\Domain\Vocabulary\Support\VocabVariantMetadataInput;
use App\Support\Identifiers\CanonicalUlid;
use DateTimeInterface;
use LogicException;

final class CreateCardData
{
    use HasCardBookSource;

    private function __construct(
        public readonly int $userId,
        public readonly string $deckId,
        public readonly string $frontText,
        public readonly string $backText,
        public readonly CardType $cardType,
        public readonly ?array $promptJson,
        public readonly ?array $answerJson,
        public readonly ?string $variantGroupId,
        public readonly ?string $variantSentenceId,
        public readonly ?VocabVariantKind $variantKind,
        public readonly ?int $variantStage,
        public readonly ?VocabVariantStatus $variantStatus,
        public readonly ?DateTimeInterface $variantUnlockedAt,
        public readonly ?string $id = null,
        public readonly ?string $introductionCohortId = null,
        public readonly CardSelectionPolicy $selectionPolicy = CardSelectionPolicy::Standard,
        public readonly ?DateTimeInterface $priorityUntil = null,
    ) {}

    public static function fromInput(
        int $userId,
        string $deckId,
        string $frontText,
        string $backText,
        CardType|string|null $cardType = null,
        ?array $promptJson = null,
        ?array $answerJson = null,
        ?string $variantGroupId = null,
        ?string $variantSentenceId = null,
        VocabVariantKind|string|null $variantKind = null,
        ?int $variantStage = null,
        VocabVariantStatus|string|null $variantStatus = null,
        ?DateTimeInterface $variantUnlockedAt = null,
        ?string $id = null,
        ?string $introductionCohortId = null,
        CardSelectionPolicy|string $selectionPolicy = CardSelectionPolicy::Standard,
        ?DateTimeInterface $priorityUntil = null,
    ): self {
        if ($userId < 1) {
            throw new LogicException('Card user ID must be a positive integer.');
        }

        VocabVariantMetadataInput::assertValidStage(
            $variantStage,
            'Card variant stage must be between 1 and 65535.',
        );

        return new self(
            userId: $userId,
            // StoreCardRequest normalizes before validation; repeat it here so direct
            // action callers get the same canonical, case-insensitive retry behavior.
            deckId: CanonicalUlid::normalize($deckId),
            frontText: trim($frontText),
            backText: trim($backText),
            cardType: $cardType === null ? CardType::Recognition : CardType::fromInput($cardType),
            promptJson: $promptJson,
            answerJson: $answerJson,
            variantGroupId: VocabVariantMetadataInput::nullableId(
                $variantGroupId,
                'Card variant IDs must be 64 characters or fewer.',
            ),
            variantSentenceId: VocabVariantMetadataInput::nullableId(
                $variantSentenceId,
                'Card variant IDs must be 64 characters or fewer.',
            ),
            variantKind: VocabVariantMetadataInput::kindFromInput($variantKind),
            variantStage: $variantStage,
            variantStatus: VocabVariantMetadataInput::statusFromInput($variantStatus),
            variantUnlockedAt: VocabVariantMetadataInput::normalizedTimestamp($variantUnlockedAt),
            id: $id === null ? null : CanonicalUlid::normalize($id),
            introductionCohortId: $introductionCohortId === null
                ? null
                : CanonicalUlid::normalize($introductionCohortId),
            selectionPolicy: is_string($selectionPolicy)
                ? CardSelectionPolicy::from($selectionPolicy)
                : $selectionPolicy,
            priorityUntil: VocabVariantMetadataInput::normalizedTimestamp($priorityUntil),
        );
    }
}
