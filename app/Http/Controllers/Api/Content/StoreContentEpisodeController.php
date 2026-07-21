<?php

namespace App\Http\Controllers\Api\Content;

use App\Domain\Content\Actions\CreateContentEpisodeAction;
use App\Domain\Content\Data\CreateContentEpisodeData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Content\StoreContentEpisodeRequest;
use App\Http\Resources\Content\ContentEpisodeResource;
use App\Http\Support\AuthenticatedUser;
use Illuminate\Http\JsonResponse;

final class StoreContentEpisodeController extends Controller
{
    public function __invoke(
        StoreContentEpisodeRequest $request,
        CreateContentEpisodeAction $action,
    ): JsonResponse {
        $data = $request->validated();
        $episode = $action->handle(CreateContentEpisodeData::fromInput(
            userId: AuthenticatedUser::id($request),
            convoLabUserId: $request->convoLabUserId(),
            title: $data['title'],
            sourceText: $data['sourceText'],
            targetLanguage: $data['targetLanguage'],
            nativeLanguage: $data['nativeLanguage'],
            audioSpeed: $data['audioSpeed'] ?? 'medium',
            jlptLevel: $data['jlptLevel'] ?? null,
            autoGenerateAudio: $data['autoGenerateAudio'] ?? true,
        ));

        return response()->json((new ContentEpisodeResource($episode))->resolve($request));
    }
}
