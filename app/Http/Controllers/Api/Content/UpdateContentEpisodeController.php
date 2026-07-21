<?php

namespace App\Http\Controllers\Api\Content;

use App\Domain\Content\Actions\UpdateContentEpisodeAction;
use App\Domain\Content\Data\UpdateContentEpisodeData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Content\UpdateContentEpisodeRequest;
use App\Http\Support\AuthenticatedUser;
use Illuminate\Http\JsonResponse;

final class UpdateContentEpisodeController extends Controller
{
    public function __invoke(
        UpdateContentEpisodeRequest $request,
        string $episodeId,
        UpdateContentEpisodeAction $action,
    ): JsonResponse {
        $updated = $action->handle(
            AuthenticatedUser::id($request),
            $episodeId,
            UpdateContentEpisodeData::fromInput($request->validated()),
        );

        if (! $updated) {
            return response()->json(['message' => 'Episode not found'], 404);
        }

        return response()->json(['message' => 'Episode updated successfully']);
    }
}
