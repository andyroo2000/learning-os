<?php

namespace App\Http\Controllers\Api\Study;

use App\Domain\Study\Actions\GetStudyImportUploadReadinessAction;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class ShowStudyImportReadinessController extends Controller
{
    public function __invoke(GetStudyImportUploadReadinessAction $getStudyImportUploadReadiness): JsonResponse
    {
        return response()->json(
            $getStudyImportUploadReadiness->handle()
        );
    }
}
