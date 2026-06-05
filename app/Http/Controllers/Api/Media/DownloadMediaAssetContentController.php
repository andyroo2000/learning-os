<?php

namespace App\Http\Controllers\Api\Media;

use App\Domain\Media\Actions\ShowMediaAssetAction;
use App\Domain\Media\Models\MediaAsset;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class DownloadMediaAssetContentController extends Controller
{
    public function __invoke(string $mediaAsset, ShowMediaAssetAction $showMediaAsset): StreamedResponse
    {
        $mediaAssetModel = $showMediaAsset->handle($mediaAsset);

        $this->authorize('view', $mediaAssetModel);

        if ($mediaAssetModel->disk !== MediaAsset::DISK_MEDIA) {
            throw new NotFoundHttpException;
        }

        $disk = Storage::disk(MediaAsset::DISK_MEDIA);

        if (! $disk->exists($mediaAssetModel->path)) {
            throw new NotFoundHttpException;
        }

        return $disk->response(
            $mediaAssetModel->path,
            $mediaAssetModel->original_filename,
            ['Content-Type' => $mediaAssetModel->mime_type],
        );
    }
}
