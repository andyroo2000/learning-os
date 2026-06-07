<?php

namespace App\Http\Controllers\Api\Study;

use App\Domain\Study\Actions\ListStudyExportMediaAssetsAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\Media\MediaAssetResource;
use App\Http\Support\AuthenticatedUser;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ListStudyExportMediaAssetsController extends Controller
{
    public function __invoke(
        Request $request,
        ListStudyExportMediaAssetsAction $listStudyExportMediaAssets,
    ): AnonymousResourceCollection {
        $userId = AuthenticatedUser::id($request);

        return MediaAssetResource::collection(
            $listStudyExportMediaAssets->handle($userId),
        );
    }
}
