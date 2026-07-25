<?php

namespace App\Http\Controllers\Api\Study;

use App\Domain\Study\Actions\BuildStudyOfflineReserveAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Study\BuildStudyOfflineReserveRequest;
use App\Http\Resources\Study\StudyCardSummaryResource;
use App\Http\Support\AuthenticatedUser;
use App\Support\DateTime\ConvoLabTimestamp;
use Illuminate\Http\JsonResponse;

class BuildStudyOfflineReserveController extends Controller
{
    public function __invoke(
        BuildStudyOfflineReserveRequest $request,
        BuildStudyOfflineReserveAction $buildStudyOfflineReserve,
    ): JsonResponse {
        $reserve = $buildStudyOfflineReserve->handle(
            userId: AuthenticatedUser::id($request),
            deckId: $request->deckId(),
            courseId: $request->courseId(),
        );

        return response()->json([
            'cards' => StudyCardSummaryResource::collection($reserve['cards'])->resolve($request),
            'reserveDays' => BuildStudyOfflineReserveAction::RESERVE_DAYS,
            'generatedAt' => ConvoLabTimestamp::serialize($reserve['generated_at']),
            'horizonEndsAt' => ConvoLabTimestamp::serialize($reserve['horizon_ends_at']),
        ]);
    }
}
