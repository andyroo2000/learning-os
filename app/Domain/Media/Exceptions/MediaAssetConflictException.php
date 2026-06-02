<?php

namespace App\Domain\Media\Exceptions;

use App\Domain\Media\Models\MediaAsset;
use RuntimeException;

final class MediaAssetConflictException extends RuntimeException
{
    private const ID_MISMATCH_MESSAGE = 'Media asset ID already exists with different metadata.';

    private const STORAGE_PATH_EXISTS_MESSAGE = 'Media asset already exists.';

    public const ID_MISMATCH_REASON = 'media_asset_id_conflict';

    public const STORAGE_PATH_EXISTS_REASON = 'media_asset_storage_conflict';

    /**
     * Owner context lets the HTTP layer hide cross-user conflicts without
     * forcing domain callers to know about response status codes.
     */
    private function __construct(
        string $message,
        private readonly ?int $conflictingUserId,
        private readonly string $reason,
    ) {
        parent::__construct($message);
    }

    public static function idMismatch(MediaAsset $mediaAsset): self
    {
        return new self(
            message: self::ID_MISMATCH_MESSAGE,
            conflictingUserId: $mediaAsset->user_id,
            reason: self::ID_MISMATCH_REASON,
        );
    }

    public static function storagePathExists(MediaAsset $mediaAsset): self
    {
        return new self(
            message: self::STORAGE_PATH_EXISTS_MESSAGE,
            conflictingUserId: $mediaAsset->user_id,
            reason: self::STORAGE_PATH_EXISTS_REASON,
        );
    }

    public static function unresolvedStorageConflict(): self
    {
        // No owner is available in the deleted-row race; return a retryable conflict
        // without hiding it behind a tenant-specific 404.
        return new self(
            message: self::STORAGE_PATH_EXISTS_MESSAGE,
            conflictingUserId: null,
            reason: self::STORAGE_PATH_EXISTS_REASON,
        );
    }

    public function shouldBeHiddenFrom(int $userId): bool
    {
        return $this->conflictingUserId !== null
            && $this->conflictingUserId !== $userId;
    }

    public function reason(): string
    {
        return $this->reason;
    }
}
