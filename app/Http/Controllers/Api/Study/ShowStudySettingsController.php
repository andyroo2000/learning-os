<?php

namespace App\Http\Controllers\Api\Study;

use App\Domain\Study\Actions\GetStudySettingsAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\Study\StudySettingsCompatibilityResource;
use App\Http\Support\AuthenticatedUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShowStudySettingsController extends Controller
{
    public function __invoke(Request $request, GetStudySettingsAction $getStudySettings): JsonResponse
    {
        return StudySettingsCompatibilityResource::make(
            $getStudySettings->handle(AuthenticatedUser::id($request)),
        )->response()->setStatusCode(200);
    }
}
