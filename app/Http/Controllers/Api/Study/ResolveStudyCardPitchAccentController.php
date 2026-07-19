<?php

namespace App\Http\Controllers\Api\Study;

use App\Domain\Study\Actions\ResolveStudyCardPitchAccentAction;
use App\Domain\Study\Exceptions\StudyCardPitchAccentConflictException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Study\ResolveStudyCardPitchAccentRequest;
use App\Http\Resources\Study\StudyCardSummaryResource;
use Illuminate\Http\JsonResponse;

class ResolveStudyCardPitchAccentController extends Controller
{
    public function __invoke(
        ResolveStudyCardPitchAccentRequest $request,
        ResolveStudyCardPitchAccentAction $resolvePitchAccent,
    ): JsonResponse {
        try {
            $card = $resolvePitchAccent->handle($request->studyCard());
        } catch (StudyCardPitchAccentConflictException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return response()->json(StudyCardSummaryResource::make($card)->resolve($request));
    }
}
