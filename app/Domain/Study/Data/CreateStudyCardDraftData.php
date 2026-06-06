<?php

namespace App\Domain\Study\Data;

use App\Domain\Flashcards\Enums\CardType;
use App\Domain\Study\Enums\StudyCardCreationKind;
use App\Domain\Study\Enums\StudyCardImagePlacement;
use App\Domain\Study\Exceptions\StudyCardDraftValidationException;
use JsonException;
use LogicException;

final readonly class CreateStudyCardDraftData
{
    public const MAX_IMAGE_PROMPT_LENGTH = 1000;

    private const MAX_PAYLOAD_BYTES = 24 * 1024;

    private const MAX_TOTAL_PAYLOAD_DEPTH = 8;

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
        try {
            $serialized = json_encode(
                ['prompt' => $promptJson, 'answer' => $answerJson],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            );
        } catch (JsonException) {
            throw StudyCardDraftValidationException::invalidPayloads();
        }

        if (strlen($serialized) > self::MAX_PAYLOAD_BYTES) {
            throw StudyCardDraftValidationException::payloadsTooLarge((int) (self::MAX_PAYLOAD_BYTES / 1024));
        }

        if (self::exceedsMaxPayloadDepth($promptJson)) {
            throw StudyCardDraftValidationException::promptTooDeep(self::MAX_TOTAL_PAYLOAD_DEPTH);
        }

        if (self::exceedsMaxPayloadDepth($answerJson)) {
            throw StudyCardDraftValidationException::answerTooDeep(self::MAX_TOTAL_PAYLOAD_DEPTH);
        }
    }

    private static function exceedsMaxPayloadDepth(mixed $value, int $depth = 1): bool
    {
        if (! is_array($value)) {
            return false;
        }

        if ($depth > self::MAX_TOTAL_PAYLOAD_DEPTH) {
            return true;
        }

        foreach ($value as $child) {
            if (self::exceedsMaxPayloadDepth($child, $depth + 1)) {
                return true;
            }
        }

        return false;
    }
}
