<?php

namespace App\Http\Controllers\Api\Study;

use App\Domain\Study\Actions\ShowDailyAudioPracticeStatusAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\Study\DailyAudioPracticeStatusResource;
use App\Http\Support\AuthenticatedUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShowDailyAudioPracticeStatusController extends Controller
{
    public function __invoke(
        Request $request,
        string $practiceId,
        ShowDailyAudioPracticeStatusAction $show,
    ): JsonResponse {
        $practice = $show->handle(AuthenticatedUser::id($request), $practiceId);

        return response()->json(DailyAudioPracticeStatusResource::make($practice)->resolve($request));
    }
}
