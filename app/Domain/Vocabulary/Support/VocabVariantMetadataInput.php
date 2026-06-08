<?php

namespace App\Domain\Vocabulary\Support;

use App\Domain\Vocabulary\Enums\VocabVariantKind;
use App\Domain\Vocabulary\Enums\VocabVariantStatus;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use LogicException;

final class VocabVariantMetadataInput
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

    public static function kindFromInput(VocabVariantKind|string|null $variantKind): ?VocabVariantKind
    {
        if ($variantKind instanceof VocabVariantKind || $variantKind === null) {
            return $variantKind;
        }

        $normalized = strtolower(trim($variantKind));

        return $normalized === '' ? null : VocabVariantKind::from($normalized);
    }

    public static function statusFromInput(VocabVariantStatus|string|null $variantStatus): ?VocabVariantStatus
    {
        if ($variantStatus instanceof VocabVariantStatus || $variantStatus === null) {
            return $variantStatus;
        }

        $normalized = strtolower(trim($variantStatus));

        return $normalized === '' ? null : VocabVariantStatus::from($normalized);
    }
}
