<?php

namespace App\Http\Controllers\Api\Study;

use App\Domain\Study\Actions\ShowDailyAudioPracticeAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\Study\DailyAudioPracticeResource;
use App\Http\Support\AuthenticatedUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShowDailyAudioPracticeController extends Controller
{
    public function __invoke(
        Request $request,
        string $practiceId,
        ShowDailyAudioPracticeAction $show,
    ): JsonResponse {
        $practice = $show->handle(AuthenticatedUser::id($request), $practiceId);

        return response()->json(DailyAudioPracticeResource::make($practice)->resolve($request));
    }
}
