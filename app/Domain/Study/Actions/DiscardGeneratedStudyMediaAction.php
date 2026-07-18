<?php

namespace App\Domain\Study\Actions;

use App\Domain\Media\Actions\DeleteMediaAssetAction;
use App\Domain\Media\Data\DeleteMediaAssetData;
use App\Domain\Media\Models\MediaAsset;
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

        if (! Storage::disk($mediaAsset->disk)->delete($mediaAsset->path)) {
            Log::warning('Generated study media cleanup left an orphaned file.', [
                'media_asset_id' => $mediaAsset->id,
                'disk' => $mediaAsset->disk,
                'path' => $mediaAsset->path,
            ]);
        }
    }
}
