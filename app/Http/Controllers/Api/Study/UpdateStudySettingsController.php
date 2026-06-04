<?php

namespace App\Http\Controllers\Api\Study;

use App\Domain\Study\Actions\UpdateStudySettingsAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Study\UpdateStudySettingsRequest;
use App\Http\Resources\Study\StudySettingsResource;
use Illuminate\Http\JsonResponse;

class UpdateStudySettingsController extends Controller
{
    public function __invoke(
        UpdateStudySettingsRequest $request,
        UpdateStudySettingsAction $updateStudySettings,
    ): JsonResponse {
        $data = $request->validated();

        return StudySettingsResource::make(
            $updateStudySettings->handle(
                userId: (int) $request->user()->id,
                newCardsPerDay: (int) $data['new_cards_per_day'],
            ),
        )->response()->setStatusCode(200);
    }
}
