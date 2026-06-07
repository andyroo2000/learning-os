<?php

namespace App\Domain\Flashcards\Data;

use App\Domain\Flashcards\Enums\CardType;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Study\Enums\StudyVocabVariantKind;
use App\Domain\Study\Enums\StudyVocabVariantStatus;
use App\Support\Identifiers\CanonicalUlid;
use Carbon\CarbonImmutable;
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
        public ?StudyVocabVariantKind $variantKind,
        public ?int $variantStage,
        public ?StudyVocabVariantStatus $variantStatus,
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
        StudyVocabVariantKind|string|null $variantKind = null,
        ?int $variantStage = null,
        StudyVocabVariantStatus|string|null $variantStatus = null,
        ?DateTimeInterface $variantUnlockedAt = null,
        ?string $id = null,
    ): self {
        if ($userId < 1) {
            throw new LogicException('Card user ID must be a positive integer.');
        }

        self::validateVariantStage($variantStage);

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
            variantGroupId: self::nullableVariantId($variantGroupId),
            variantSentenceId: self::nullableVariantId($variantSentenceId),
            variantKind: self::variantKindFromInput($variantKind),
            variantStage: $variantStage,
            variantStatus: self::variantStatusFromInput($variantStatus),
            variantUnlockedAt: self::normalizedTimestamp($variantUnlockedAt),
            id: $id === null ? null : CanonicalUlid::normalize($id),
        );
    }

    private static function nullableTrimmedString(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private static function nullableVariantId(?string $value): ?string
    {
        $trimmed = self::nullableTrimmedString($value);

        if ($trimmed === null) {
            return null;
        }

        if (mb_strlen($trimmed, 'UTF-8') > Card::MAX_VARIANT_ID_LENGTH) {
            throw new LogicException('Card variant IDs must be 64 characters or fewer.');
        }

        return $trimmed;
    }

    private static function validateVariantStage(?int $variantStage): void
    {
        if ($variantStage === null) {
            return;
        }

        if ($variantStage < 1 || $variantStage > Card::MAX_VARIANT_STAGE) {
            throw new LogicException('Card variant stage must be between 1 and 65535.');
        }
    }

    private static function normalizedTimestamp(?DateTimeInterface $value): ?DateTimeInterface
    {
        return $value === null ? null : CarbonImmutable::instance($value)->startOfSecond();
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
}
