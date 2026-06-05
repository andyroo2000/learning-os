<?php

namespace App\Http\Controllers\Api\Study;

use App\Domain\Study\Actions\GetStudySettingsAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\Study\StudySettingsResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShowStudyExportSettingsController extends Controller
{
    public function __invoke(Request $request, GetStudySettingsAction $getStudySettings): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return StudySettingsResource::make(
            $getStudySettings->handle($user->id),
        )->response()->setStatusCode(200);
    }
}
