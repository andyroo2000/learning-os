<?php

namespace App\Http\Controllers\Api\Content;

use App\Domain\Content\Actions\CreateContentEpisodeAction;
use App\Domain\Content\Data\CreateContentEpisodeData;
use App\Domain\Content\Exceptions\ContentCreationConflictException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Content\StoreContentEpisodeRequest;
use App\Http\Resources\Content\ContentEpisodeResource;
use Illuminate\Http\JsonResponse;

final class StoreContentEpisodeController extends Controller
{
    public function __invoke(
        StoreContentEpisodeRequest $request,
        CreateContentEpisodeAction $action,
    ): JsonResponse {
        $data = $request->validated();
        try {
            $result = $action->handle(CreateContentEpisodeData::fromInput(
                userId: $request->contentUserId(),
                convoLabUserId: $request->convoLabUserId(),
                title: $data['title'],
                sourceText: $data['sourceText'],
                targetLanguage: $data['targetLanguage'],
                nativeLanguage: $data['nativeLanguage'],
                audioSpeed: $data['audioSpeed'] ?? 'medium',
                jlptLevel: $data['jlptLevel'] ?? null,
                autoGenerateAudio: $data['autoGenerateAudio'] ?? true,
                id: $data['id'] ?? null,
            ));
        } catch (ContentCreationConflictException $exception) {
            return $this->conflictResponse($exception, $request);
        }

        return response()->json([
            ...(new ContentEpisodeResource($result->episode))->resolve($request),
            'existing' => ! $result->wasCreated,
        ]);
    }

    private function conflictResponse(
        ContentCreationConflictException $exception,
        StoreContentEpisodeRequest $request,
    ): JsonResponse {
        if ($exception->shouldBeHiddenFrom($request->contentUserId(), $request->convoLabUserId())) {
            return response()->json(['message' => 'Episode not found'], 404);
        }

        return response()->json([
            'code' => $exception->isGone() ? 'content_gone' : 'idempotency_conflict',
            'message' => $exception->getMessage(),
        ], $exception->isGone() ? 410 : 409);
    }
}
