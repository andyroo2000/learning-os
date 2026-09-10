<?php

namespace App\Domain\Study\Actions;

use App\Domain\Media\Actions\CreateMediaAssetAction;
use App\Domain\Media\Data\CreateMediaAssetData;
use App\Domain\Media\Models\MediaAsset;
use App\Domain\Study\Exceptions\StudyCardAudioValidationException;
use App\Domain\Study\Exceptions\StudyPreviewMediaGenerationException;
use App\Domain\Study\Models\StudyCardDraft;
use App\Domain\Study\Results\GeneratedStudyMediaResult;
use App\Domain\Study\Support\PcmWav;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class PersistUploadedStudyAudioAction
{
    public const MAX_UPLOAD_KILOBYTES = 10 * 1024;

    public const MAX_UPLOAD_BYTES = self::MAX_UPLOAD_KILOBYTES * 1024;

    public const MAX_DURATION_SECONDS = 60;

    public function __construct(
        private readonly CreateMediaAssetAction $createMediaAsset,
    ) {}

    public function handle(int $userId, UploadedFile $audio): GeneratedStudyMediaResult
    {
        $bytes = $this->validatedBytes($audio);

        $mediaAssetId = (string) Str::ulid();
        $filename = strtolower($mediaAssetId).'.wav';
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
                mimeType: 'audio/wav',
                sizeBytes: strlen($bytes),
                checksumSha256: hash('sha256', $bytes),
                originalFilename: $audio->getClientOriginalName(),
                id: $mediaAssetId,
            ))->mediaAsset;
        } catch (Throwable $exception) {
            if (! $disk->delete($path)) {
                Log::warning('Failed to clean up uploaded study audio after persistence failed.', [
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
                'mediaKind' => 'audio',
                'source' => StudyCardDraft::MEDIA_SOURCE_IMPORTED,
            ],
        );
    }

    private function validatedBytes(UploadedFile $audio): string
    {
        $bytes = $audio->get();
        if (! is_string($bytes) || $bytes === '') {
            throw StudyCardAudioValidationException::invalidUpload();
        }
        if (strlen($bytes) > self::MAX_UPLOAD_BYTES) {
            throw StudyCardAudioValidationException::uploadTooLarge(
                intdiv(self::MAX_UPLOAD_KILOBYTES, 1024),
            );
        }

        PcmWav::validate($bytes, self::MAX_DURATION_SECONDS);

        return $bytes;
    }
}
