<?php

namespace App\Http\Controllers\Api\Content;

use App\Domain\Content\Actions\ShowContentEpisodeAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Content\ShowContentEpisodeRequest;
use App\Http\Resources\Content\ContentEpisodeResource;
use Illuminate\Http\JsonResponse;

class ShowContentEpisodeController extends Controller
{
    public function __invoke(
        ShowContentEpisodeRequest $request,
        string $episodeId,
        ShowContentEpisodeAction $action,
    ): JsonResponse {
        $episode = $action->handle(
            $request->contentUserId(),
            $request->convoLabUserId(),
            $episodeId,
        );

        return response()
            ->json((new ContentEpisodeResource($episode))->resolve($request))
            ->header('Cache-Control', 'private, max-age=60');
    }
}
