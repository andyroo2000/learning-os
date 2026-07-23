<?php

namespace App\Http\Controllers\Api\Content;

use App\Domain\Content\Actions\CreateContentAudioScriptAction;
use App\Domain\Content\Actions\RunQuotaLimitedContentGenerationAction;
use App\Domain\Content\Data\CreateContentAudioScriptData;
use App\Domain\Content\Enums\ContentGenerationType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Content\StoreContentAudioScriptRequest;
use App\Http\Resources\Content\ContentEpisodeResource;
use Illuminate\Http\JsonResponse;

final class StoreContentAudioScriptController extends Controller
{
    public function __invoke(
        StoreContentAudioScriptRequest $request,
        CreateContentAudioScriptAction $action,
        RunQuotaLimitedContentGenerationAction $generation,
    ): JsonResponse {
        $data = $request->validated();
        $episode = $generation->handle(
            $request->convoLabUserId(),
            ContentGenerationType::Script,
            null,
            fn () => $action->handle(CreateContentAudioScriptData::fromInput(
                $request->contentUserId(),
                $request->convoLabUserId(),
                $data['sourceText'],
                $data['voiceId'] ?? null,
            )),
            fn ($result): string => $result->id,
        );

        return response()->json((new ContentEpisodeResource($episode))->resolve($request));
    }
}
