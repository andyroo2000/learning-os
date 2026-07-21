<?php

namespace App\Http\Controllers\Api\Content;

use App\Domain\Content\Actions\CreateContentAudioScriptAction;
use App\Domain\Content\Data\CreateContentAudioScriptData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Content\StoreContentAudioScriptRequest;
use App\Http\Resources\Content\ContentEpisodeResource;
use App\Http\Support\AuthenticatedUser;
use Illuminate\Http\JsonResponse;

final class StoreContentAudioScriptController extends Controller
{
    public function __invoke(
        StoreContentAudioScriptRequest $request,
        CreateContentAudioScriptAction $action,
    ): JsonResponse {
        $data = $request->validated();
        $episode = $action->handle(CreateContentAudioScriptData::fromInput(
            AuthenticatedUser::id($request),
            $request->convoLabUserId(),
            $data['sourceText'],
            $data['voiceId'] ?? null,
        ));

        return response()->json((new ContentEpisodeResource($episode))->resolve($request));
    }
}
