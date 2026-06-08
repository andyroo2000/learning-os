<?php

namespace App\Domain\Flashcards\Data;

use App\Domain\Flashcards\Enums\CardType;
use App\Domain\Vocabulary\Enums\VocabVariantKind;
use App\Domain\Vocabulary\Enums\VocabVariantStatus;
use App\Domain\Vocabulary\Support\VocabVariantMetadataInput;
use App\Support\Identifiers\CanonicalUlid;
use DateTimeInterface;
use LogicException;

final readonly class CreateCardData
{
    private function __construct(
        public int $userId,
        public string $deckId,
        public string $frontText,
        public string $backText,
        public CardType $cardType,
        public ?array $promptJson,
        public ?array $answerJson,
        public ?string $variantGroupId,
        public ?string $variantSentenceId,
        public ?VocabVariantKind $variantKind,
        public ?int $variantStage,
        public ?VocabVariantStatus $variantStatus,
        public ?DateTimeInterface $variantUnlockedAt,
        public ?string $id = null,
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
        );
    }
}
