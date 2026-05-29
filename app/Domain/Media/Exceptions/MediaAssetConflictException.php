<?php

namespace App\Domain\Media\Exceptions;

use App\Domain\Media\Models\MediaAsset;
use RuntimeException;

final class MediaAssetConflictException extends RuntimeException
{
    /**
     * Owner context lets the HTTP layer hide cross-user conflicts without
     * forcing domain callers to know about response status codes.
     */
    private function __construct(
        string $message,
        private readonly ?int $conflictingUserId,
    ) {
        parent::__construct($message);
    }

    public static function idMismatch(MediaAsset $mediaAsset): self
    {
        return new self(
            message: 'Media asset ID already exists with different metadata.',
            conflictingUserId: $mediaAsset->user_id,
        );
    }

    public static function storagePathExists(MediaAsset $mediaAsset): self
    {
        return new self(
            message: 'Media asset already exists.',
            conflictingUserId: $mediaAsset->user_id,
        );
    }

    public static function unresolvedStorageConflict(): self
    {
        // No owner is available in the deleted-row race; return a retryable conflict
        // without hiding it behind a tenant-specific 404.
        return new self(
            message: 'Media asset already exists.',
            conflictingUserId: null,
        );
    }

    public function shouldBeHiddenFrom(int $userId): bool
    {
        return $this->conflictingUserId !== null
            && $this->conflictingUserId !== $userId;
    }
}
