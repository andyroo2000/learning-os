<?php

namespace App\Http\Controllers\Api\Study;

use App\Domain\Study\Actions\ListStudyCardBatchAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Study\ListStudyCardBatchRequest;
use App\Http\Resources\Study\StudyCardSummaryResource;
use App\Http\Support\AuthenticatedUser;
use Illuminate\Http\JsonResponse;

class ListStudyCardBatchController extends Controller
{
    public function __invoke(
        ListStudyCardBatchRequest $request,
        ListStudyCardBatchAction $listStudyCardBatch,
    ): JsonResponse {
        $cards = $listStudyCardBatch->handle(
            AuthenticatedUser::id($request),
            $request->ids(),
        );

        return response()->json([
            'cards' => StudyCardSummaryResource::collection($cards)->resolve($request),
        ]);
    }
}
