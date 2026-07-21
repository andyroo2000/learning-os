<?php

namespace App\Http\Controllers\Api\Content;

use App\Domain\Content\Actions\DeleteContentEpisodeAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Content\DeleteContentEpisodeRequest;
use App\Http\Support\AuthenticatedUser;
use Illuminate\Http\JsonResponse;

final class DeleteContentEpisodeController extends Controller
{
    public function __invoke(
        DeleteContentEpisodeRequest $request,
        string $episodeId,
        DeleteContentEpisodeAction $action,
    ): JsonResponse {
        if (! $action->handle(
            AuthenticatedUser::id($request),
            $request->convoLabUserId(),
            $episodeId,
        )) {
            return response()->json(['message' => 'Episode not found'], 404);
        }

        return response()->json(['message' => 'Episode deleted successfully']);
    }
}
