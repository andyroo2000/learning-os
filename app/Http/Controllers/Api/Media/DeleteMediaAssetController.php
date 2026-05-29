<?php

namespace App\Http\Controllers\Api\Media;

use App\Domain\Media\Actions\DeleteMediaAssetAction;
use App\Domain\Media\Data\DeleteMediaAssetData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Media\DeleteMediaAssetRequest;
use Illuminate\Http\Response;

class DeleteMediaAssetController extends Controller
{
    public function __invoke(
        DeleteMediaAssetRequest $request,
        DeleteMediaAssetAction $deleteMediaAsset,
    ): Response {
        $deleteMediaAsset->handle(DeleteMediaAssetData::fromInput(
            userId: $request->userId(),
            mediaAssetId: $request->mediaAssetId(),
        ));

        return response()->noContent();
    }
}
