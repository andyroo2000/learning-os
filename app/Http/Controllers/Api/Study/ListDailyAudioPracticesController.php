<?php

namespace App\Http\Controllers\Api\Study;

use App\Domain\Study\Actions\ListDailyAudioPracticesAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\Study\DailyAudioPracticeSummaryResource;
use App\Http\Support\AuthenticatedUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ListDailyAudioPracticesController extends Controller
{
    public function __invoke(Request $request, ListDailyAudioPracticesAction $list): JsonResponse
    {
        $practices = $list->handle(AuthenticatedUser::id($request));

        return response()->json(DailyAudioPracticeSummaryResource::collection($practices)->resolve($request));
    }
}
