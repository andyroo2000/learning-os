<?php

namespace App\Domain\Media\Queries;

/**
 * Single source of truth for media columns exposed in offline manifests.
 */
final class MediaAssetManifestProjection
{
    public const ATTRIBUTES = [
        'id',
        'public_url',
        'mime_type',
        'size_bytes',
        'checksum_sha256',
        'original_filename',
        'created_at',
        'updated_at',
    ];

    /**
     * @return list<string>
     */
    public static function columns(string $table = 'media_assets'): array
    {
        return array_map(
            fn (string $attribute): string => $table.'.'.$attribute,
            self::ATTRIBUTES,
        );
    }
}
