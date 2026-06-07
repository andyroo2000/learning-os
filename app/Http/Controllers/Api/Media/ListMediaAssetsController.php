<?php

namespace App\Http\Controllers\Api\Media;

use App\Domain\Media\Actions\ListMediaAssetsAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Media\ListMediaAssetsRequest;
use App\Http\Resources\Media\MediaAssetResource;
use App\Http\Support\AuthenticatedUser;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ListMediaAssetsController extends Controller
{
    public function __invoke(ListMediaAssetsRequest $request, ListMediaAssetsAction $listMediaAssets): AnonymousResourceCollection
    {
        $userId = AuthenticatedUser::id($request);

        return MediaAssetResource::collection(
            $listMediaAssets->handle(
                userId: $userId,
                pageSize: $request->pageSize(),
                courseId: $request->courseId(),
                deckId: $request->deckId(),
            )->withQueryString()
        );
    }
}
