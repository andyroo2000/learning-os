<?php

namespace App\Http\Controllers\Api\Study;

use App\Http\Controllers\Controller;
use App\Http\Requests\Study\ShowStudyCardRequest;
use App\Http\Resources\Study\StudyCardSummaryResource;
use Illuminate\Http\JsonResponse;

class ShowStudyCardController extends Controller
{
    public function __invoke(ShowStudyCardRequest $request): JsonResponse
    {
        return response()->json(
            StudyCardSummaryResource::make($request->studyCard())->resolve($request),
        );
    }
}
