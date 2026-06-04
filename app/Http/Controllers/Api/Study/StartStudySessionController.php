<?php

namespace App\Http\Controllers\Api\Study;

use App\Domain\Study\Actions\StartStudySessionAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Study\StartStudySessionRequest;
use App\Http\Resources\Study\StudySessionResource;
use Illuminate\Http\JsonResponse;

class StartStudySessionController extends Controller
{
    public function __invoke(
        StartStudySessionRequest $request,
        StartStudySessionAction $startStudySession,
    ): JsonResponse {
        $data = $request->validated();

        return StudySessionResource::make(
            $startStudySession->handle(
                userId: (int) $request->user()->id,
                timeZone: $data['time_zone'] ?? null,
            ),
        )->response()->setStatusCode(200);
    }
}
