<?php

namespace App\Domain\Study\Actions;

use App\Domain\Media\Actions\CreateMediaAssetAction;
use App\Domain\Media\Data\CreateMediaAssetData;
use App\Domain\Media\Models\MediaAsset;
use App\Domain\Study\Exceptions\StudyCardImageValidationException;
use App\Domain\Study\Exceptions\StudyPreviewMediaGenerationException;
use App\Domain\Study\Models\StudyCardDraft;
use App\Domain\Study\Results\GeneratedStudyMediaResult;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class PersistUploadedStudyImageAction
{
    public const MAX_UPLOAD_KILOBYTES = 10 * 1024;

    public const MAX_UPLOAD_BYTES = self::MAX_UPLOAD_KILOBYTES * 1024;

    public function __construct(
        private readonly CreateMediaAssetAction $createMediaAsset,
    ) {}

    public function handle(int $userId, UploadedFile $image): GeneratedStudyMediaResult
    {
        $bytes = $image->get();
        if (! is_string($bytes) || $bytes === '') {
            throw StudyCardImageValidationException::invalidUpload();
        }
        if (strlen($bytes) > self::MAX_UPLOAD_BYTES) {
            throw StudyCardImageValidationException::uploadTooLarge(10);
        }

        $imageInfo = @getimagesizefromstring($bytes);
        $mimeType = is_array($imageInfo) && is_string($imageInfo['mime'] ?? null)
            ? $imageInfo['mime']
            : '';
        $extension = match ($mimeType) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => throw StudyCardImageValidationException::invalidUpload(),
        };

        $mediaAssetId = (string) Str::ulid();
        $filename = strtolower($mediaAssetId).'.'.$extension;
        $path = "study/uploads/{$userId}/{$filename}";
        $disk = Storage::disk(MediaAsset::DISK_MEDIA);

        if (! $disk->put($path, $bytes)) {
            throw StudyPreviewMediaGenerationException::storageFailed();
        }

        try {
            $mediaAsset = $this->createMediaAsset->handle(CreateMediaAssetData::fromInput(
                userId: $userId,
                disk: MediaAsset::DISK_MEDIA,
                path: $path,
                mimeType: $mimeType,
                sizeBytes: strlen($bytes),
                checksumSha256: hash('sha256', $bytes),
                originalFilename: $image->getClientOriginalName(),
                id: $mediaAssetId,
            ))->mediaAsset;
        } catch (Throwable $exception) {
            if (! $disk->delete($path)) {
                Log::warning('Failed to clean up an uploaded study image after persistence failed.', [
                    'disk' => MediaAsset::DISK_MEDIA,
                    'path' => $path,
                ]);
            }

            throw StudyPreviewMediaGenerationException::storageFailed($exception);
        }

        return new GeneratedStudyMediaResult(
            mediaAsset: $mediaAsset,
            mediaRef: [
                'id' => $mediaAsset->id,
                'filename' => $filename,
                'url' => "/api/study/media/{$mediaAsset->id}",
                'mediaKind' => 'image',
                'source' => StudyCardDraft::MEDIA_SOURCE_IMPORTED_IMAGE,
            ],
        );
    }
}
