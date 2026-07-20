<?php

namespace App\Http\Controllers\Api\Content;

use App\Domain\Content\Actions\ListContentEpisodesAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Content\ListContentEpisodesRequest;
use App\Http\Resources\Content\ContentEpisodeLibraryResource;
use App\Http\Resources\Content\ContentEpisodeResource;
use Illuminate\Http\JsonResponse;

class ListContentEpisodesController extends Controller
{
    public function __invoke(
        ListContentEpisodesRequest $request,
        ListContentEpisodesAction $action,
    ): JsonResponse {
        $episodes = $action->handle(
            $request->user()->getKey(),
            $request->limit(),
            $request->offset(),
            $request->library(),
        );

        $resources = $request->library()
            ? ContentEpisodeLibraryResource::collection($episodes)
            : ContentEpisodeResource::collection($episodes);

        return response()->json($resources->resolve($request));
    }
}
