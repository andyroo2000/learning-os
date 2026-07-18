<?php

namespace App\Domain\Study\Actions;

use App\Domain\Media\Actions\CreateMediaAssetAction;
use App\Domain\Media\Data\CreateMediaAssetData;
use App\Domain\Media\Models\MediaAsset;
use App\Domain\Study\Exceptions\StudyPreviewMediaGenerationException;
use App\Domain\Study\Models\StudyCardDraft;
use App\Domain\Study\Results\GeneratedStudyMediaResult;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class PersistGeneratedStudyMediaAction
{
    public const MAX_GENERATED_BYTES = 20 * 1024 * 1024;

    public function __construct(
        private readonly CreateMediaAssetAction $createMediaAsset,
    ) {}

    public function handle(
        int $userId,
        string $bytes,
        string $mediaKind,
        string $mimeType,
        string $extension,
    ): GeneratedStudyMediaResult {
        $sizeBytes = strlen($bytes);
        if ($sizeBytes < 1 || $sizeBytes > self::MAX_GENERATED_BYTES) {
            throw StudyPreviewMediaGenerationException::invalidProviderOutput('Preview media provider');
        }

        if (! in_array($mediaKind, ['audio', 'image'], true)) {
            throw StudyPreviewMediaGenerationException::invalidProviderOutput('Preview media provider');
        }

        $mediaAssetId = (string) Str::ulid();
        $filename = strtolower($mediaAssetId).'.'.$extension;
        $path = "study/generated/{$userId}/{$filename}";
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
                sizeBytes: $sizeBytes,
                checksumSha256: hash('sha256', $bytes),
                originalFilename: $filename,
                id: $mediaAssetId,
            ))->mediaAsset;
        } catch (Throwable $exception) {
            if (! $disk->delete($path)) {
                Log::warning('Failed to clean up generated study media after persistence failed.', [
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
                'mediaKind' => $mediaKind,
                'source' => StudyCardDraft::MEDIA_SOURCE_GENERATED,
            ],
        );
    }
}
