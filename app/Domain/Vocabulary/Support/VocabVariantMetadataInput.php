<?php

namespace App\Domain\Vocabulary\Support;

use App\Domain\Vocabulary\Enums\VocabVariantKind;
use App\Domain\Vocabulary\Enums\VocabVariantStatus;
use App\Support\DateTime\StrictIsoDateTime;
use App\Support\VariantMetadataLimits;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use LogicException;

final class VocabVariantMetadataInput
{
    public const MAX_ID_LENGTH = VariantMetadataLimits::MAX_ID_LENGTH;

    public const MAX_STAGE = VariantMetadataLimits::MAX_STAGE;

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
        return $value === null ? null : CarbonImmutable::instance($value)->utc()->startOfSecond();
    }

    public static function canonicalUnlockedAtTimestamp(string $value): ?CarbonImmutable
    {
        $parsed = StrictIsoDateTime::parseOrNull(trim($value));

        return $parsed === null ? null : CarbonImmutable::instance($parsed)->utc()->startOfSecond();
    }

    public static function compatibilityUnlockedAtTimestamp(string $value): ?CarbonImmutable
    {
        $trimmed = trim($value);
        $parsed = self::canonicalUnlockedAtTimestamp($trimmed);

        if ($parsed !== null) {
            return $parsed;
        }

        $matches = self::bareTimestampComponents($trimmed);

        if ($matches === null) {
            return null;
        }

        return CarbonImmutable::create(
            (int) $matches['year'],
            (int) $matches['month'],
            (int) $matches['day'],
            (int) $matches['hour'],
            (int) $matches['minute'],
            (int) $matches['second'],
            'UTC',
        )->setMicrosecond((int) str_pad(substr($matches['fraction'] ?? '', 0, 6), 6, '0'));
    }

    public static function kindFromInput(VocabVariantKind|string|null $variantKind): ?VocabVariantKind
    {
        if ($variantKind instanceof VocabVariantKind || $variantKind === null) {
            return $variantKind;
        }

        $normalized = strtolower(trim($variantKind));

        if ($normalized === '') {
            return null;
        }

        return VocabVariantKind::tryFrom($normalized)
            ?? throw new LogicException('Variant kind must be one of: '.implode(', ', VocabVariantKind::values()).'.');
    }

    public static function statusFromInput(VocabVariantStatus|string|null $variantStatus): ?VocabVariantStatus
    {
        if ($variantStatus instanceof VocabVariantStatus || $variantStatus === null) {
            return $variantStatus;
        }

        $normalized = strtolower(trim($variantStatus));

        if ($normalized === '') {
            return null;
        }

        return VocabVariantStatus::tryFrom($normalized)
            ?? throw new LogicException('Variant status must be one of: '.implode(', ', VocabVariantStatus::values()).'.');
    }

    /**
     * @return array<string, string>|null
     */
    private static function bareTimestampComponents(string $value): ?array
    {
        $matched = preg_match(
            '/^(?<year>\d{4})-(?<month>\d{2})-(?<day>\d{2})T(?<hour>\d{2}):(?<minute>\d{2}):(?<second>\d{2})(?:\.(?<fraction>\d+))?$/',
            $value,
            $matches,
        );

        if ($matched !== 1) {
            return null;
        }

        if (! checkdate((int) $matches['month'], (int) $matches['day'], (int) $matches['year'])) {
            return null;
        }

        if ((int) $matches['hour'] > 23 || (int) $matches['minute'] > 59 || (int) $matches['second'] > 59) {
            return null;
        }

        return $matches;
    }
}
