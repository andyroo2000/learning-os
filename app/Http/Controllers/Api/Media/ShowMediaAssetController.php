<?php

namespace App\Http\Controllers\Api\Media;

use App\Domain\Media\Models\MediaAsset;
use App\Http\Controllers\Controller;
use App\Http\Resources\Media\MediaAssetResource;
use Illuminate\Support\Str;

class ShowMediaAssetController extends Controller
{
    public function __invoke(string $mediaAsset): MediaAssetResource
    {
        if (! Str::isUlid($mediaAsset)) {
            abort(404);
        }

        $mediaAssetModel = MediaAsset::findOrFail($mediaAsset);

        $this->authorize('view', $mediaAssetModel);

        return MediaAssetResource::make($mediaAssetModel);
    }
}
