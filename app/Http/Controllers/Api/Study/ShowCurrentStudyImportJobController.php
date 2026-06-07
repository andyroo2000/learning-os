<?php

namespace App\Http\Controllers\Api\Study;

use App\Domain\Study\Actions\GetCurrentStudyImportJobAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\Study\StudyImportJobResource;
use App\Http\Support\AuthenticatedUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShowCurrentStudyImportJobController extends Controller
{
    public function __invoke(
        Request $request,
        GetCurrentStudyImportJobAction $getCurrentStudyImportJob,
    ): JsonResponse|StudyImportJobResource {
        $userId = AuthenticatedUser::id($request);
        $importJob = $getCurrentStudyImportJob->handle($userId);

        if ($importJob === null) {
            return response()->json(['data' => null]);
        }

        return StudyImportJobResource::make(
            $importJob,
        );
    }
}
