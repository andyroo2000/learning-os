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
use Illuminate\Support\Str;
use InvalidArgumentException;

class CreateMediaAssetAction
{
    /**
     * PostgreSQL callers should not wrap this action in a transaction without
     * revisiting retry recovery; constraint violations abort the transaction.
     */
    public function handle(CreateMediaAssetData $data): MediaAsset
    {
        if ($data->userId < 1) {
            throw new MediaAssetValidationException('Media asset user ID must be a positive integer.');
        }

        if ($data->disk === '') {
            throw new MediaAssetValidationException('Media asset disk is required.');
        }

        if (mb_strlen($data->disk) > MediaAsset::MAX_DISK_LENGTH) {
            throw new MediaAssetValidationException('Media asset disk must not exceed '.MediaAsset::MAX_DISK_LENGTH.' characters.');
        }

        if ($data->path === '') {
            throw new MediaAssetValidationException('Media asset path is required.');
        }

        if (mb_strlen($data->path) > MediaAsset::MAX_PATH_LENGTH) {
            throw new MediaAssetValidationException('Media asset path must not exceed '.MediaAsset::MAX_PATH_LENGTH.' characters.');
        }

        if ($data->mimeType === '') {
            throw new MediaAssetValidationException('Media asset MIME type is required.');
        }

        if (mb_strlen($data->mimeType) > MediaAsset::MAX_MIME_TYPE_LENGTH) {
            throw new MediaAssetValidationException('Media asset MIME type must not exceed '.MediaAsset::MAX_MIME_TYPE_LENGTH.' characters.');
        }

        // Expects CreateMediaAssetData's normalized lowercase form; keep accepted MIME
        // types conservative until upload adapters need wider token support.
        if (! MimeType::hasValidShape($data->mimeType)) {
            throw new MediaAssetValidationException('Media asset MIME type must include a type and subtype.');
        }

        if ($data->sizeBytes < 1) {
            throw new MediaAssetValidationException('Media asset size must be at least 1 byte.');
        }

        // No product cap here: size_bytes is unsignedBigInteger; upload caps belong at the upload boundary.
        if ($data->checksumSha256 !== null && ! $this->isSha256Checksum($data->checksumSha256)) {
            throw new MediaAssetValidationException('Media asset checksum must be a 64-character SHA-256 hex digest.');
        }

        if ($data->publicUrl !== null) {
            try {
                PublicUrl::assertValid($data->publicUrl, MediaAsset::MAX_PUBLIC_URL_LENGTH);
            } catch (InvalidArgumentException $exception) {
                throw new MediaAssetValidationException($exception->getMessage(), previous: $exception);
            }
        }

        // Validate the already-normalized basename against the stored column limit.
        if ($data->originalFilename !== null && mb_strlen($data->originalFilename) > MediaAsset::MAX_ORIGINAL_FILENAME_LENGTH) {
            throw new MediaAssetValidationException('Media asset original filename must not exceed '.MediaAsset::MAX_ORIGINAL_FILENAME_LENGTH.' characters.');
        }

        if ($data->id !== null) {
            if (! Str::isUlid($data->id)) {
                throw new MediaAssetValidationException('Media asset ID must be a valid ULID.');
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
            if ($data->id === null || ! IntegrityConstraintViolation::matches($exception)) {
                throw $exception;
            }

            // Covers a retry race where another request inserts this client-generated ULID
            // between the pre-check above and this save attempt.
            $existingMediaAsset = MediaAsset::query()->find($data->id);

            if ($existingMediaAsset === null) {
                // The integrity violation came from a different constraint, such as disk/path,
                // rather than an idempotent retry for this client-generated ID.
                throw $exception;
            }

            return $this->matchingExistingMediaAsset($existingMediaAsset, $data);
        }

        return $mediaAsset;
    }

    private function isSha256Checksum(string $value): bool
    {
        return strlen($value) === 64 && ctype_xdigit($value);
    }

    private function matchingExistingMediaAsset(MediaAsset $mediaAsset, CreateMediaAssetData $data): MediaAsset
    {
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
            throw new MediaAssetConflictException('Media asset ID already exists with different metadata.');
        }

        return $mediaAsset;
    }
}
