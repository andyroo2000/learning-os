<?php

namespace App\Http\Controllers\Api\Study;

use App\Domain\Study\Actions\BuildPersonalWeeklyRecapAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Study\ShowPersonalWeeklyRecapRequest;
use App\Http\Support\AuthenticatedUser;
use Illuminate\Http\JsonResponse;

final class ShowPersonalWeeklyRecapController extends Controller
{
    public function __invoke(
        ShowPersonalWeeklyRecapRequest $request,
        BuildPersonalWeeklyRecapAction $recap,
    ): JsonResponse {
        return response()->json($recap->handle(
            AuthenticatedUser::id($request),
            $request->timezone(),
            $request->weekStartsOn(),
        ));
    }
}
