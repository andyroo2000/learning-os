<?php

namespace App\Domain\Study\Actions;

use App\Domain\Media\Actions\DeleteMediaAssetAction;
use App\Domain\Media\Data\DeleteMediaAssetData;
use App\Domain\Media\Models\MediaAsset;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class DiscardGeneratedStudyMediaAction
{
    public function __construct(
        private readonly DeleteMediaAssetAction $deleteMediaAsset,
    ) {}

    public function handle(MediaAsset $mediaAsset): void
    {
        try {
            $this->deleteMediaAsset->handle(DeleteMediaAssetData::fromInput(
                userId: $mediaAsset->user_id,
                mediaAssetId: $mediaAsset->id,
            ));
        } catch (Throwable $exception) {
            // This runs while preserving an earlier draft-update failure. Keep the
            // database row and file together for later cleanup, and do not mask that failure.
            Log::error('Generated study media cleanup could not delete its media asset.', [
                'media_asset_id' => $mediaAsset->id,
                'exception' => $exception,
            ]);

            return;
        }

        $this->deleteFile($mediaAsset);
    }

    public function handleIfUnreferenced(MediaAsset $mediaAsset): void
    {
        try {
            $deletedMediaAsset = DB::transaction(function () use ($mediaAsset): ?MediaAsset {
                // Lock the parent row so concurrent FK-backed card attachments cannot
                // slip between the reference check and hard delete on PostgreSQL.
                $lockedMediaAsset = MediaAsset::query()
                    ->whereKey($mediaAsset->id)
                    ->where('user_id', $mediaAsset->user_id)
                    ->lockForUpdate()
                    ->first();

                if ($lockedMediaAsset === null || DB::table('card_media')
                    ->where('media_asset_id', $lockedMediaAsset->id)
                    ->exists()) {
                    return null;
                }

                $this->deleteMediaAsset->handle(DeleteMediaAssetData::fromInput(
                    userId: $lockedMediaAsset->user_id,
                    mediaAssetId: $lockedMediaAsset->id,
                ));

                return $lockedMediaAsset;
            });
        } catch (Throwable $exception) {
            // Replacement already committed; cleanup must not turn that success into a 500.
            Log::error('Unreferenced generated study media cleanup failed.', [
                'media_asset_id' => $mediaAsset->id,
                'exception' => $exception,
            ]);

            return;
        }

        if ($deletedMediaAsset !== null) {
            $this->deleteFile($deletedMediaAsset);
        }
    }

    private function deleteFile(MediaAsset $mediaAsset): void
    {
        if (! Storage::disk($mediaAsset->disk)->delete($mediaAsset->path)) {
            Log::warning('Generated study media cleanup left an orphaned file.', [
                'media_asset_id' => $mediaAsset->id,
                'disk' => $mediaAsset->disk,
                'path' => $mediaAsset->path,
            ]);
        }
    }
}
