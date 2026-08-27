<?php

namespace App\Http\Controllers\Api\Achievements;

use App\Domain\Achievements\Actions\GetAchievementProgressAction;
use App\Http\Controllers\Controller;
use App\Http\Support\AuthenticatedUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ShowAchievementProgressController extends Controller
{
    public function __invoke(Request $request, GetAchievementProgressAction $action): JsonResponse
    {
        return response()
            ->json($action->handle(AuthenticatedUser::id($request)))
            ->header('Cache-Control', 'private, no-store');
    }
}
