<?php

namespace App\Http\Controllers\Api\Media;

use App\Domain\Media\Actions\ListMediaAssetsAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\Media\MediaAssetResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ListMediaAssetsController extends Controller
{
    public function __invoke(Request $request, ListMediaAssetsAction $listMediaAssets): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();
        $perPage = $request->integer('per_page', ListMediaAssetsAction::DEFAULT_PAGE_SIZE);

        return MediaAssetResource::collection($listMediaAssets->handle($user->id, $perPage));
    }
}
