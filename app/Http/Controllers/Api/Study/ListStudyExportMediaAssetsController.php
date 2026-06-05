<?php

namespace App\Http\Controllers\Api\Study;

use App\Domain\Study\Actions\ListStudyExportMediaAssetsAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\Media\MediaAssetResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ListStudyExportMediaAssetsController extends Controller
{
    public function __invoke(
        Request $request,
        ListStudyExportMediaAssetsAction $listStudyExportMediaAssets,
    ): AnonymousResourceCollection {
        /** @var User $user */
        $user = $request->user();

        return MediaAssetResource::collection(
            $listStudyExportMediaAssets->handle($user->id),
        );
    }
}
