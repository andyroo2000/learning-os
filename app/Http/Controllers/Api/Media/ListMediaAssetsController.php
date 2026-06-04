<?php

namespace App\Http\Controllers\Api\Media;

use App\Domain\Media\Actions\ListMediaAssetsAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Media\ListMediaAssetsRequest;
use App\Http\Resources\Media\MediaAssetResource;
use App\Models\User;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ListMediaAssetsController extends Controller
{
    public function __invoke(ListMediaAssetsRequest $request, ListMediaAssetsAction $listMediaAssets): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        return MediaAssetResource::collection(
            $listMediaAssets->handle($user->id, $request->pageSize(), $request->courseId())->withQueryString()
        );
    }
}
