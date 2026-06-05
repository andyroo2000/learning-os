<?php

namespace App\Http\Controllers\Api\Study;

use App\Domain\Study\Actions\GetStudyExportManifestAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\Study\StudyExportManifestResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShowStudyExportManifestController extends Controller
{
    public function __invoke(Request $request, GetStudyExportManifestAction $getStudyExportManifest): JsonResponse
    {
        return StudyExportManifestResource::make(
            $getStudyExportManifest->handle((int) $request->user()->id),
        )->response()->setStatusCode(200);
    }
}
