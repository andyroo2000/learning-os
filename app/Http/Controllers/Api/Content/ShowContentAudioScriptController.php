<?php

namespace App\Http\Controllers\Api\Content;

use App\Domain\Content\Actions\ShowContentAudioScriptAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Content\ShowContentAudioScriptRequest;
use App\Http\Resources\Content\ContentAudioScriptResource;
use Illuminate\Http\JsonResponse;

final class ShowContentAudioScriptController extends Controller
{
    public function __invoke(
        ShowContentAudioScriptRequest $request,
        string $episodeId,
        ShowContentAudioScriptAction $action,
    ): JsonResponse {
        $script = $action->handle(
            $request->contentUserId(),
            $request->convoLabUserId(),
            $episodeId,
        );

        return response()->json((new ContentAudioScriptResource($script))->resolve($request));
    }
}
