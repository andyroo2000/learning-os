<?php

namespace App\Domain\Media\Actions;

use App\Domain\Media\Data\CreateMediaAssetData;
use App\Domain\Media\Exceptions\MediaAssetConflictException;
use App\Domain\Media\Exceptions\MediaAssetValidationException;
use App\Domain\Media\Models\MediaAsset;
use App\Domain\Media\Values\MimeType;
use App\Domain\Media\Values\PublicUrl;
use App\Support\Database\IntegrityConstraintViolation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

class CreateMediaAssetAction
{
    /**
     * PostgreSQL callers should not wrap this action in a transaction without
     * revisiting retry recovery; constraint violations abort the transaction.
     *
     * @throws MediaAssetConflictException when a client ULID or disk/path pair conflicts.
     */
    public function handle(CreateMediaAssetData $data): MediaAsset
    {
        if ($data->userId < 1) {
            throw new LogicException('Media asset user ID must be a positive integer.');
        }

        if ($data->disk === '') {
            throw new MediaAssetValidationException('disk', 'Media asset disk is required.');
        }

        if (mb_strlen($data->disk) > MediaAsset::MAX_DISK_LENGTH) {
            throw new MediaAssetValidationException('disk', 'Media asset disk must not exceed '.MediaAsset::MAX_DISK_LENGTH.' characters.');
        }

        if (! in_array($data->disk, MediaAsset::ALLOWED_DISKS, true)) {
            throw new MediaAssetValidationException('disk', 'Media asset disk is not supported.');
        }

        if ($data->path === '') {
            throw new MediaAssetValidationException('path', 'Media asset path is required.');
        }

        if (mb_strlen($data->path) > MediaAsset::MAX_PATH_LENGTH) {
            throw new MediaAssetValidationException('path', 'Media asset path must not exceed '.MediaAsset::MAX_PATH_LENGTH.' characters.');
        }

        if (preg_match('~(^|[\\\\/])\\.\\.([\\\\/]|$)~', $data->path) === 1) {
            throw new MediaAssetValidationException('path', 'Media asset path must not contain traversal sequences.');
        }

        if ($data->mimeType === '') {
            throw new MediaAssetValidationException('mime_type', 'Media asset MIME type is required.');
        }

        if (mb_strlen($data->mimeType) > MediaAsset::MAX_MIME_TYPE_LENGTH) {
            throw new MediaAssetValidationException('mime_type', 'Media asset MIME type must not exceed '.MediaAsset::MAX_MIME_TYPE_LENGTH.' characters.');
        }

        // Keep action-level guards in sync with HTTP validation so non-HTTP callers
        // cannot bypass media invariants. The DTO provides normalized lowercase form.
        if (! MimeType::hasValidNormalizedShape($data->mimeType)) {
            throw new MediaAssetValidationException('mime_type', 'Media asset MIME type must include a type and subtype.');
        }

        if ($data->sizeBytes < 1) {
            throw new MediaAssetValidationException('size_bytes', 'Media asset size must be at least 1 byte.');
        }

        // No product cap here: size_bytes is unsignedBigInteger; upload caps belong at the upload boundary.
        if ($data->checksumSha256 !== null && ! $this->isSha256Checksum($data->checksumSha256)) {
            throw new MediaAssetValidationException('checksum_sha256', 'Media asset checksum must be a 64-character SHA-256 hex digest.');
        }

        if ($data->publicUrl !== null) {
            try {
                PublicUrl::assertValid($data->publicUrl, MediaAsset::MAX_PUBLIC_URL_LENGTH);
            } catch (InvalidArgumentException $exception) {
                throw new MediaAssetValidationException('public_url', $exception->getMessage(), $exception);
            }
        }

        // Validate the already-normalized basename against the stored column limit.
        if ($data->originalFilename !== null && mb_strlen($data->originalFilename) > MediaAsset::MAX_ORIGINAL_FILENAME_LENGTH) {
            throw new MediaAssetValidationException('original_filename', 'Media asset original filename must not exceed '.MediaAsset::MAX_ORIGINAL_FILENAME_LENGTH.' characters.');
        }

        if ($data->id !== null) {
            if (! Str::isUlid($data->id)) {
                throw new MediaAssetValidationException('id', 'Media asset ID must be a valid ULID.');
            }

            $existingMediaAsset = MediaAsset::query()->find($data->id);

            if ($existingMediaAsset !== null) {
                return $this->matchingExistingMediaAsset($existingMediaAsset, $data);
            }
        }

        $mediaAsset = new MediaAsset([
            'user_id' => $data->userId,
            'disk' => $data->disk,
            'path' => $data->path,
            'mime_type' => $data->mimeType,
            'size_bytes' => $data->sizeBytes,
            'checksum_sha256' => $data->checksumSha256,
            'original_filename' => $data->originalFilename,
        ]);

        if ($data->id !== null) {
            $mediaAsset->id = $data->id;
        }

        // public_url is intentionally not fillable; assign it explicitly after invariants are checked.
        $mediaAsset->public_url = $data->publicUrl;

        try {
            $mediaAsset->save();
        } catch (QueryException $exception) {
            if (! IntegrityConstraintViolation::matches($exception)) {
                throw $exception;
            }

            if ($data->id !== null) {
                // Covers a retry race where another request inserts this client-generated ULID
                // between the pre-check above and this save attempt.
                $existingMediaAsset = MediaAsset::query()->find($data->id);

                if ($existingMediaAsset !== null) {
                    return $this->matchingExistingMediaAsset($existingMediaAsset, $data);
                }
            }

            $existingMediaAsset = MediaAsset::query()
                ->where('disk', $data->disk)
                ->where('path', $data->path)
                ->first();

            if ($existingMediaAsset !== null) {
                throw MediaAssetConflictException::storagePathExists($existingMediaAsset);
            }

            // If the conflicting row disappeared before this lookup, preserve the original
            // database failure so callers see the true unresolved write result.
            Log::warning('Media asset integrity violation could not be mapped to an existing asset.', [
                'disk' => $data->disk,
                'path' => $data->path,
                'id' => $data->id,
            ]);

            throw $exception;
        }

        return $mediaAsset;
    }

    private function isSha256Checksum(string $value): bool
    {
        return strlen($value) === 64 && ctype_xdigit($value);
    }

    private function matchingExistingMediaAsset(MediaAsset $mediaAsset, CreateMediaAssetData $data): MediaAsset
    {
        // Ownership is part of retry identity; matching metadata from a different user
        // is still a conflict so the API can hide the other user's asset.
        // Idempotency compares normalized immutable metadata. Original filename uses the
        // same value object in the DTO and model accessor; raw imports should be normalized
        // before relying on client-generated ID retries for these rows.
        // public_url is immutable create metadata; later server-assigned URLs should use a
        // separate update action rather than relaxing idempotent retry matching.
        if (
            $mediaAsset->user_id !== $data->userId
            || $mediaAsset->disk !== $data->disk
            || $mediaAsset->path !== $data->path
            || $mediaAsset->mime_type !== $data->mimeType
            || $mediaAsset->size_bytes !== $data->sizeBytes
            || $mediaAsset->public_url !== $data->publicUrl
            || $mediaAsset->checksum_sha256 !== $data->checksumSha256
            || $mediaAsset->original_filename !== $data->originalFilename
        ) {
            throw MediaAssetConflictException::idMismatch($mediaAsset);
        }

        return $mediaAsset;
    }
}
