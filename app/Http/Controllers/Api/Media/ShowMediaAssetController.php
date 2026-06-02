<?php

namespace App\Http\Controllers\Api\Media;

use App\Domain\Media\Actions\ShowMediaAssetAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\Media\MediaAssetResource;

class ShowMediaAssetController extends Controller
{
    public function __invoke(string $mediaAsset, ShowMediaAssetAction $showMediaAsset): MediaAssetResource
    {
        $mediaAssetModel = $showMediaAsset->handle($mediaAsset);

        $this->authorize('view', $mediaAssetModel);

        return MediaAssetResource::make($mediaAssetModel);
    }
}
