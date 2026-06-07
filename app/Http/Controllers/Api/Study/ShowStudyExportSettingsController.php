<?php

namespace App\Http\Controllers\Api\Study;

use App\Domain\Study\Actions\GetStudySettingsAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\Study\StudySettingsResource;
use App\Http\Support\AuthenticatedUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShowStudyExportSettingsController extends Controller
{
    public function __invoke(Request $request, GetStudySettingsAction $getStudySettings): JsonResponse
    {
        $userId = AuthenticatedUser::id($request);

        return StudySettingsResource::make(
            $getStudySettings->handle($userId),
        )->response()->setStatusCode(200);
    }
}
