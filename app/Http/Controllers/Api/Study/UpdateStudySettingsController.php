<?php

namespace App\Http\Controllers\Api\Study;

use App\Domain\Study\Actions\UpdateStudySettingsAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Study\UpdateStudySettingsRequest;
use App\Http\Resources\Study\StudySettingsCompatibilityResource;
use App\Http\Support\AuthenticatedUser;
use Illuminate\Http\JsonResponse;

class UpdateStudySettingsController extends Controller
{
    public function __invoke(
        UpdateStudySettingsRequest $request,
        UpdateStudySettingsAction $updateStudySettings,
    ): JsonResponse {
        return StudySettingsCompatibilityResource::make(
            $updateStudySettings->handle(
                userId: AuthenticatedUser::id($request),
                newCardsPerDay: $request->newCardsPerDay(),
            ),
        )->response()->setStatusCode(200);
    }
}
