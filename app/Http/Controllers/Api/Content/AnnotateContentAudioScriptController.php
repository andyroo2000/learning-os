<?php

namespace App\Http\Controllers\Api\Content;

use App\Domain\Content\Actions\AnnotateContentAudioScriptAction;
use App\Domain\Content\Exceptions\ContentAudioScriptConflictException;
use App\Domain\Content\Exceptions\ContentAudioScriptGenerationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Content\MutateContentAudioScriptRequest;
use App\Http\Resources\Content\ContentAudioScriptResource;
use Illuminate\Http\JsonResponse;

final class AnnotateContentAudioScriptController extends Controller
{
    public function __invoke(
        MutateContentAudioScriptRequest $request,
        string $episodeId,
        AnnotateContentAudioScriptAction $action,
    ): JsonResponse {
        try {
            $script = $action->handle(
                $request->contentUserId(),
                $request->convoLabUserId(),
                $episodeId,
            );
        } catch (ContentAudioScriptConflictException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        } catch (ContentAudioScriptGenerationException $exception) {
            return response()->json(['message' => $exception->getMessage()], 502);
        }

        return response()->json((new ContentAudioScriptResource($script))->resolve($request));
    }
}
