<?php

namespace App\Domain\Study\Data;

use App\Domain\Study\Support\HasCardBookSource;
use App\Domain\Study\Support\HasLearnSoonPreference;
use InvalidArgumentException;
use LogicException;

final class CreateStudyVocabBundleData
{
    use HasCardBookSource;
    use HasLearnSoonPreference;

    public const MAX_TARGET_WORD_LENGTH = 500;

    public const MAX_SOURCE_SENTENCE_LENGTH = 500;

    public const MAX_CONTEXT_LENGTH = 2000;

    private function __construct(
        public readonly int $userId,
        public readonly string $targetWord,
        public readonly ?string $sourceSentence,
        public readonly ?string $context,
        public readonly bool $includeLearnerContext,
        public readonly ?int $waniKaniSubjectId,
    ) {}

    public static function fromInput(
        int $userId,
        string $targetWord,
        ?string $sourceSentence,
        ?string $context,
        bool $includeLearnerContext,
        ?int $waniKaniSubjectId = null,
    ): self {
        if ($userId < 1) {
            throw new LogicException('Study vocab bundle user ID must be a positive integer.');
        }
        if ($waniKaniSubjectId !== null && $waniKaniSubjectId < 1) {
            throw new LogicException('WaniKani subject ID must be a positive integer.');
        }

        $normalizedTargetWord = trim($targetWord);
        $normalizedSourceSentence = self::nullableTrimmed($sourceSentence);
        $normalizedContext = self::nullableTrimmed($context);

        if ($normalizedTargetWord === '') {
            throw new InvalidArgumentException('Study vocab bundle target word is required.');
        }
        if (mb_strlen($normalizedTargetWord) > self::MAX_TARGET_WORD_LENGTH) {
            throw new InvalidArgumentException('Study vocab bundle target word is too long.');
        }
        if ($normalizedSourceSentence !== null
            && mb_strlen($normalizedSourceSentence) > self::MAX_SOURCE_SENTENCE_LENGTH) {
            throw new InvalidArgumentException('Study vocab bundle source sentence is too long.');
        }
        if ($normalizedContext !== null && mb_strlen($normalizedContext) > self::MAX_CONTEXT_LENGTH) {
            throw new InvalidArgumentException('Study vocab bundle context is too long.');
        }

        return new self(
            userId: $userId,
            targetWord: $normalizedTargetWord,
            sourceSentence: $normalizedSourceSentence,
            context: $normalizedContext,
            includeLearnerContext: $includeLearnerContext,
            waniKaniSubjectId: $waniKaniSubjectId,
        );
    }

    private static function nullableTrimmed(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
