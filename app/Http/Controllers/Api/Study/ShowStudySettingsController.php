<?php

namespace App\Http\Controllers\Api\Study;

use App\Domain\Study\Actions\GetStudySettingsAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\Study\StudySettingsResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShowStudySettingsController extends Controller
{
    public function __invoke(Request $request, GetStudySettingsAction $getStudySettings): JsonResponse
    {
        return StudySettingsResource::make(
            $getStudySettings->handle((int) $request->user()->id),
        )->response()->setStatusCode(200);
    }
}
