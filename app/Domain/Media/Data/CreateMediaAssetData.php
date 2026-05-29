<?php

namespace App\Domain\Media\Data;

use App\Domain\Media\Values\OriginalFilename;

final readonly class CreateMediaAssetData
{
    private function __construct(
        public int $userId,
        public string $disk,
        public string $path,
        public string $mimeType,
        public int $sizeBytes,
        public ?string $publicUrl = null,
        public ?string $checksumSha256 = null,
        public ?string $originalFilename = null,
        public ?string $id = null,
    ) {}

    public static function fromInput(
        int $userId,
        string $disk,
        string $path,
        string $mimeType,
        int $sizeBytes,
        ?string $publicUrl = null,
        ?string $checksumSha256 = null,
        ?string $originalFilename = null,
        ?string $id = null,
    ): self {
        return new self(
            userId: $userId,
            disk: trim($disk),
            path: trim($path),
            mimeType: self::normalizeMimeType($mimeType),
            sizeBytes: $sizeBytes,
            publicUrl: self::normalizeOptionalString($publicUrl),
            checksumSha256: self::normalizeChecksum($checksumSha256),
            originalFilename: self::normalizeOriginalFilename($originalFilename),
            id: self::normalizeId($id),
        );
    }

    private static function normalizeOptionalString(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private static function normalizeMimeType(string $value): string
    {
        return strtolower(trim(explode(';', trim($value), 2)[0]));
    }

    private static function normalizeChecksum(?string $value): ?string
    {
        $value = self::normalizeOptionalString($value);

        return $value === null ? null : strtolower($value);
    }

    private static function normalizeOriginalFilename(?string $value): ?string
    {
        return OriginalFilename::normalize($value);
    }

    private static function normalizeId(?string $value): ?string
    {
        $value = self::normalizeOptionalString($value);

        return $value === null ? null : strtolower($value);
    }
}
