<?php

namespace App\Http\Controllers\Api\Achievements;

use App\Domain\Achievements\Actions\EvaluateAchievementProgressAction;
use App\Http\Controllers\Controller;
use App\Http\Support\AuthenticatedUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class EvaluateAchievementProgressController extends Controller
{
    public function __invoke(Request $request, EvaluateAchievementProgressAction $action): JsonResponse
    {
        return response()
            ->json($action->handle(AuthenticatedUser::id($request)))
            ->header('Cache-Control', 'private, no-store');
    }
}
