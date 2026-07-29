<?php

namespace App\Http\Controllers\Api\Study;

use App\Domain\Study\Actions\BuildStudyActivityAnalyticsAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Study\ShowStudyActivityAnalyticsRequest;
use App\Http\Support\AuthenticatedUser;
use Illuminate\Http\JsonResponse;

final class ShowStudyActivityAnalyticsController extends Controller
{
    public function __invoke(
        ShowStudyActivityAnalyticsRequest $request,
        BuildStudyActivityAnalyticsAction $analytics,
    ): JsonResponse {
        return response()->json($analytics->handle(
            AuthenticatedUser::id($request),
            $request->timezone(),
            $request->weekStartsOn(),
        ));
    }
}
