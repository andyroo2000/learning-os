<?php

namespace App\Domain\Media\Actions;

use App\Domain\Media\Data\CreateMediaAssetData;
use App\Domain\Media\Exceptions\MediaAssetConflictException;
use App\Domain\Media\Exceptions\MediaAssetValidationException;
use App\Domain\Media\Models\MediaAsset;
use App\Domain\Media\Results\CreateMediaAssetResult;
use App\Domain\Media\Values\MimeType;
use App\Domain\Media\Values\PublicUrl;
use App\Domain\Sync\Enums\SyncFeedOperation;
use App\Support\Database\IntegrityConstraintViolation;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use Throwable;

class CreateMediaAssetAction
{
    /** @internal Test-only race seam; see tests/Feature/Media/CreateMediaAssetActionTest.php. */
    public function __construct(
        private readonly RecordMediaAssetSyncFeedEntryAction $recordMediaAssetSyncFeedEntry,
        private readonly ?Closure $afterClientIdPrecheckMiss = null,
    ) {
        if ($afterClientIdPrecheckMiss !== null && ! app()->runningUnitTests()) {
            throw new LogicException('Media asset creation race hooks may only be used in tests.');
        }
    }

    /**
     * This action manages its own transaction. Do not wrap it in an outer transaction:
     * constraint-violation rollbacks target the innermost savepoint and can leave
     * the outer transaction aborted on PostgreSQL.
     *
     * @throws MediaAssetConflictException when a client ULID or disk/path pair conflicts.
     */
    public function handle(CreateMediaAssetData $data): CreateMediaAssetResult
    {
        $this->validate($data);

        $existingMediaAsset = $this->existingMediaAssetForClientId($data);
        if ($existingMediaAsset !== null) {
            return CreateMediaAssetResult::existing($existingMediaAsset);
        }

        return $this->createNewMediaAsset($this->newMediaAsset($data), $data);
    }

    private function validate(CreateMediaAssetData $data): void
    {
        if ($data->userId < 1) {
            throw new LogicException('Media asset user ID must be a positive integer.');
        }

        $this->validateStorageLocation($data);
        $this->validateMediaMetadata($data);
    }

    private function validateStorageLocation(CreateMediaAssetData $data): void
    {
        $this->validateDisk($data);
        $this->validatePath($data);
        $this->validateMimeType($data);
    }

    private function validateMediaMetadata(CreateMediaAssetData $data): void
    {
        $this->validateSize($data);
        $this->validateChecksum($data);
        $this->validatePublicUrl($data);
        $this->validateOriginalFilename($data);
    }

    private function validateDisk(CreateMediaAssetData $data): void
    {
        if ($data->disk === '') {
            throw new MediaAssetValidationException('disk', 'Media asset disk is required.');
        }

        if (mb_strlen($data->disk) > MediaAsset::MAX_DISK_LENGTH) {
            throw new MediaAssetValidationException('disk', 'Media asset disk must not exceed '.MediaAsset::MAX_DISK_LENGTH.' characters.');
        }

        if (! in_array($data->disk, MediaAsset::ALLOWED_DISKS, true)) {
            throw new MediaAssetValidationException('disk', 'Media asset disk is not supported.');
        }
    }

    private function validatePath(CreateMediaAssetData $data): void
    {
        if ($data->path === '') {
            throw new MediaAssetValidationException('path', 'Media asset path is required.');
        }

        if (mb_strlen($data->path) > MediaAsset::MAX_PATH_LENGTH) {
            throw new MediaAssetValidationException('path', 'Media asset path must not exceed '.MediaAsset::MAX_PATH_LENGTH.' characters.');
        }

        $this->validatePathShape($data);
    }

    private function validatePathShape(CreateMediaAssetData $data): void
    {
        if (preg_match(MediaAsset::PATH_ABSOLUTE_PATTERN, $data->path) === 1) {
            throw new MediaAssetValidationException('path', 'Media asset path must be relative.');
        }

        if (preg_match(MediaAsset::PATH_TRAVERSAL_PATTERN, $data->path) === 1) {
            throw new MediaAssetValidationException('path', 'Media asset path must not contain traversal sequences.');
        }
    }

    private function validateMimeType(CreateMediaAssetData $data): void
    {
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
    }

    private function validateSize(CreateMediaAssetData $data): void
    {
        if ($data->sizeBytes < 1) {
            throw new MediaAssetValidationException('size_bytes', 'Media asset size must be at least 1 byte.');
        }

        if ($data->sizeBytes > MediaAsset::MAX_JSON_SAFE_SIZE_BYTES) {
            throw new MediaAssetValidationException('size_bytes', 'Media asset size must not exceed '.MediaAsset::MAX_JSON_SAFE_SIZE_BYTES.' bytes.');
        }
    }

    private function validateChecksum(CreateMediaAssetData $data): void
    {
        // No product upload cap here; this only preserves JSON integer precision for API clients.
        if ($data->checksumSha256 !== null && ! $this->isSha256Checksum($data->checksumSha256)) {
            throw new MediaAssetValidationException('checksum_sha256', 'Media asset checksum must be a 64-character SHA-256 hex digest.');
        }
    }

    private function validatePublicUrl(CreateMediaAssetData $data): void
    {
        if ($data->publicUrl !== null) {
            try {
                PublicUrl::assertValid($data->publicUrl, MediaAsset::MAX_PUBLIC_URL_LENGTH);
            } catch (InvalidArgumentException $exception) {
                throw new MediaAssetValidationException('public_url', $exception->getMessage(), $exception);
            }
        }
    }

    private function validateOriginalFilename(CreateMediaAssetData $data): void
    {
        // Validate the already-normalized basename against the stored column limit.
        if ($data->originalFilename !== null && mb_strlen($data->originalFilename) > MediaAsset::MAX_ORIGINAL_FILENAME_LENGTH) {
            throw new MediaAssetValidationException('original_filename', 'Media asset original filename must not exceed '.MediaAsset::MAX_ORIGINAL_FILENAME_LENGTH.' characters.');
        }
    }

    private function existingMediaAssetForClientId(CreateMediaAssetData $data): ?MediaAsset
    {
        if ($data->id === null) {
            return null;
        }

        if (! Str::isUlid($data->id)) {
            throw new MediaAssetValidationException('id', 'Media asset ID must be a valid ULID.');
        }

        $existingMediaAsset = MediaAsset::query()->find($data->id);
        if ($existingMediaAsset !== null) {
            return $this->matchingExistingMediaAsset($existingMediaAsset, $data);
        }

        if ($this->afterClientIdPrecheckMiss !== null) {
            ($this->afterClientIdPrecheckMiss)($data);
        }

        return null;
    }

    private function newMediaAsset(CreateMediaAssetData $data): MediaAsset
    {
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

        return $mediaAsset;
    }

    private function createNewMediaAsset(MediaAsset $mediaAsset, CreateMediaAssetData $data): CreateMediaAssetResult
    {
        // Manual control lets constraint recovery read after rollback while keeping feed recording atomic.
        DB::beginTransaction();

        try {
            $mediaAsset->save();
        } catch (QueryException $exception) {
            DB::rollBack();

            return $this->recoverFromConstraintViolation($exception, $data);
        } catch (Throwable $exception) {
            DB::rollBack();

            throw $exception;
        }

        try {
            $this->recordMediaAssetSyncFeedEntry->handle(
                userId: $data->userId,
                operation: SyncFeedOperation::Create,
                mediaAsset: $mediaAsset,
            );

            DB::commit();
        } catch (Throwable $exception) {
            DB::rollBack();

            throw $exception;
        }

        return CreateMediaAssetResult::created($mediaAsset);
    }

    private function recoverFromConstraintViolation(
        QueryException $exception,
        CreateMediaAssetData $data,
    ): CreateMediaAssetResult {
        if (! IntegrityConstraintViolation::matches($exception)) {
            throw $exception;
        }

        $clientIdRetry = $this->clientIdRetryResult($data);
        if ($clientIdRetry !== null) {
            return $clientIdRetry;
        }

        if (! IntegrityConstraintViolation::matchesUniqueKey($exception)) {
            throw $exception;
        }

        $this->throwStoragePathConflict($data);

        // If the conflicting row disappeared before this lookup, keep the client-facing
        // retry signal as a conflict while logging the unresolved database detail.
        Log::warning('Media asset integrity violation could not be mapped to an existing asset.', [
            'disk' => $data->disk,
            'path' => $data->path,
            'id' => $data->id,
        ]);

        throw MediaAssetConflictException::unresolvedStorageConflict();
    }

    private function clientIdRetryResult(CreateMediaAssetData $data): ?CreateMediaAssetResult
    {
        if ($data->id === null) {
            return null;
        }

        // Covers a retry race where another request inserts this client-generated ULID
        // between the pre-check above and this save attempt.
        $existingMediaAsset = MediaAsset::query()->find($data->id);
        if ($existingMediaAsset === null) {
            return null;
        }

        return CreateMediaAssetResult::existing($this->matchingExistingMediaAsset($existingMediaAsset, $data));
    }

    private function throwStoragePathConflict(CreateMediaAssetData $data): void
    {
        $existingMediaAsset = MediaAsset::query()
            ->where('disk', $data->disk)
            ->where('path', $data->path)
            ->first();

        if ($existingMediaAsset !== null) {
            throw MediaAssetConflictException::storagePathExists($existingMediaAsset);
        }
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
        $storedIdentity = [
            'user_id' => $mediaAsset->user_id,
            'disk' => $mediaAsset->disk,
            'path' => $mediaAsset->path,
            'mime_type' => $mediaAsset->mime_type,
            'size_bytes' => $mediaAsset->size_bytes,
            'public_url' => $mediaAsset->public_url,
            'checksum_sha256' => $mediaAsset->checksum_sha256,
            'original_filename' => $mediaAsset->original_filename,
        ];
        $requestedIdentity = [
            'user_id' => $data->userId,
            'disk' => $data->disk,
            'path' => $data->path,
            'mime_type' => $data->mimeType,
            'size_bytes' => $data->sizeBytes,
            'public_url' => $data->publicUrl,
            'checksum_sha256' => $data->checksumSha256,
            'original_filename' => $data->originalFilename,
        ];
        ksort($storedIdentity);
        ksort($requestedIdentity);

        if ($storedIdentity !== $requestedIdentity) {
            throw MediaAssetConflictException::idMismatch($mediaAsset);
        }

        return $mediaAsset;
    }
}
