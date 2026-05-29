<?php

namespace App\Domain\Media\Exceptions;

use App\Domain\Media\Models\MediaAsset;
use RuntimeException;

final class MediaAssetConflictException extends RuntimeException
{
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

    public function conflictingUserId(): ?int
    {
        return $this->conflictingUserId;
    }
}
