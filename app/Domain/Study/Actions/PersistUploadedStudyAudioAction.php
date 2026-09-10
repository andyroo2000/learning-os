<?php

namespace App\Domain\Study\Actions;

use App\Domain\Media\Actions\CreateMediaAssetAction;
use App\Domain\Media\Data\CreateMediaAssetData;
use App\Domain\Media\Models\MediaAsset;
use App\Domain\Study\Exceptions\StudyCardAudioValidationException;
use App\Domain\Study\Exceptions\StudyPreviewMediaGenerationException;
use App\Domain\Study\Models\StudyCardDraft;
use App\Domain\Study\Results\GeneratedStudyMediaResult;
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
        $bytes = $audio->get();
        if (! is_string($bytes) || $bytes === '') {
            throw StudyCardAudioValidationException::invalidUpload();
        }
        if (strlen($bytes) > self::MAX_UPLOAD_BYTES) {
            throw StudyCardAudioValidationException::uploadTooLarge(
                intdiv(self::MAX_UPLOAD_KILOBYTES, 1024),
            );
        }

        $this->assertValidPcmWav($bytes);

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

    private function assertValidPcmWav(string $bytes): void
    {
        $riffSize = strlen($bytes) >= 8
            ? (unpack('Vsize', substr($bytes, 4, 4))['size'] ?? null)
            : null;
        if (strlen($bytes) < 44
            || substr($bytes, 0, 4) !== 'RIFF'
            || substr($bytes, 8, 4) !== 'WAVE'
            || $riffSize !== strlen($bytes) - 8) {
            throw StudyCardAudioValidationException::invalidUpload();
        }

        $offset = 12;
        $format = null;
        $dataBytes = null;
        while ($offset + 8 <= strlen($bytes)) {
            $chunkType = substr($bytes, $offset, 4);
            $chunkSize = unpack('Vsize', substr($bytes, $offset + 4, 4))['size'] ?? null;
            $paddedChunkEnd = $offset + 8 + $chunkSize + ($chunkSize % 2);
            if (! is_int($chunkSize) || $chunkSize < 0 || $paddedChunkEnd > strlen($bytes)) {
                throw StudyCardAudioValidationException::invalidUpload();
            }
            $chunk = substr($bytes, $offset + 8, $chunkSize);
            if ($chunkType === 'fmt ' && $chunkSize >= 16) {
                if ($format !== null) {
                    throw StudyCardAudioValidationException::invalidUpload();
                }
                $values = unpack('vformat/vchannels/VsampleRate/VbyteRate/vblockAlign/vbits', substr($chunk, 0, 16));
                $format = is_array($values) ? $values : null;
            } elseif ($chunkType === 'data') {
                if ($dataBytes !== null) {
                    throw StudyCardAudioValidationException::invalidUpload();
                }
                $dataBytes = $chunkSize;
            }
            $offset = $paddedChunkEnd;
        }

        if ($offset !== strlen($bytes)) {
            throw StudyCardAudioValidationException::invalidUpload();
        }

        $channels = $format['channels'] ?? null;
        $sampleRate = $format['sampleRate'] ?? null;
        $bits = $format['bits'] ?? null;
        $blockAlign = $format['blockAlign'] ?? null;
        if (($format['format'] ?? null) !== 1
            || ! in_array($channels, [1, 2], true)
            || ! is_int($sampleRate)
            || $sampleRate < 8_000
            || $sampleRate > 96_000
            || $bits !== 16
            || $blockAlign !== $channels * 2
            || ($format['byteRate'] ?? null) !== $sampleRate * $blockAlign
            || ! is_int($dataBytes)
            || $dataBytes < $blockAlign
            || $dataBytes % $blockAlign !== 0) {
            throw StudyCardAudioValidationException::invalidUpload();
        }

        $duration = $dataBytes / ($sampleRate * $blockAlign);
        if ($duration > self::MAX_DURATION_SECONDS) {
            throw StudyCardAudioValidationException::uploadTooLong(self::MAX_DURATION_SECONDS);
        }
    }
}
