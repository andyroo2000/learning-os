<?php

namespace App\Http\Controllers\Api\Content;

use App\Domain\Content\Actions\UpdateContentAudioScriptSegmentsAction;
use App\Domain\Content\Data\UpdateContentAudioScriptData;
use App\Domain\Content\Exceptions\ContentAudioScriptConflictException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Content\UpdateContentAudioScriptSegmentsRequest;
use App\Http\Resources\Content\ContentAudioScriptResource;
use App\Http\Support\AuthenticatedUser;
use Illuminate\Http\JsonResponse;

final class UpdateContentAudioScriptSegmentsController extends Controller
{
    public function __invoke(
        UpdateContentAudioScriptSegmentsRequest $request,
        string $episodeId,
        UpdateContentAudioScriptSegmentsAction $action,
    ): JsonResponse {
        $input = $request->validated();

        try {
            $script = $action->handle(UpdateContentAudioScriptData::fromInput(
                AuthenticatedUser::id($request),
                $request->convoLabUserId(),
                $episodeId,
                $input['title'] ?? null,
                $input['voiceId'] ?? null,
                $input['segments'],
            ));
        } catch (ContentAudioScriptConflictException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return response()->json((new ContentAudioScriptResource($script))->resolve($request));
    }
}
