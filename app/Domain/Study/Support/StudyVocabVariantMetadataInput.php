<?php

namespace App\Domain\Study\Support;

use App\Domain\Study\Enums\StudyVocabVariantKind;
use App\Domain\Study\Enums\StudyVocabVariantStatus;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use LogicException;

final class StudyVocabVariantMetadataInput
{
    public const MAX_ID_LENGTH = 64;

    public const MAX_STAGE = 65535;

    public static function nullableId(?string $value, string $exceptionMessage): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        if ($trimmed === '') {
            return null;
        }

        if (mb_strlen($trimmed, 'UTF-8') > self::MAX_ID_LENGTH) {
            throw new LogicException($exceptionMessage);
        }

        return $trimmed;
    }

    public static function assertValidStage(?int $variantStage, string $exceptionMessage): void
    {
        if ($variantStage === null) {
            return;
        }

        if ($variantStage < 1 || $variantStage > self::MAX_STAGE) {
            throw new LogicException($exceptionMessage);
        }
    }

    public static function normalizedTimestamp(?DateTimeInterface $value): ?DateTimeInterface
    {
        return $value === null ? null : CarbonImmutable::instance($value)->startOfSecond();
    }

    public static function kindFromInput(StudyVocabVariantKind|string|null $variantKind): ?StudyVocabVariantKind
    {
        if ($variantKind instanceof StudyVocabVariantKind || $variantKind === null) {
            return $variantKind;
        }

        return StudyVocabVariantKind::from(strtolower(trim($variantKind)));
    }

    public static function statusFromInput(StudyVocabVariantStatus|string|null $variantStatus): ?StudyVocabVariantStatus
    {
        if ($variantStatus instanceof StudyVocabVariantStatus || $variantStatus === null) {
            return $variantStatus;
        }

        return StudyVocabVariantStatus::from(strtolower(trim($variantStatus)));
    }
}
