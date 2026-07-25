<?php

namespace App\Http\Controllers\Api\Study;

use App\Domain\Study\Actions\ListDailyAudioPracticesAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Study\ListDailyAudioPracticesRequest;
use App\Http\Resources\Study\DailyAudioPracticeSummaryResource;
use App\Http\Support\AuthenticatedUser;
use Illuminate\Http\JsonResponse;

class ListDailyAudioPracticesController extends Controller
{
    public function __invoke(
        ListDailyAudioPracticesRequest $request,
        ListDailyAudioPracticesAction $list,
    ): JsonResponse {
        $userId = AuthenticatedUser::id($request);

        if ($request->paginated()) {
            $page = $list->handlePage(
                userId: $userId,
                cursor: $request->cursor(),
                limit: $request->limit(),
            );

            return response()->json([
                'items' => DailyAudioPracticeSummaryResource::collection($page['items'])->resolve($request),
                'total' => $page['total'],
                'limit' => $page['limit'],
                'nextCursor' => $page['nextCursor'],
            ]);
        }

        $practices = $list->handle($userId);

        return response()->json(DailyAudioPracticeSummaryResource::collection($practices)->resolve($request));
    }
}
