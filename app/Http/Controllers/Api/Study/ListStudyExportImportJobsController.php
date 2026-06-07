<?php

namespace App\Http\Controllers\Api\Study;

use App\Domain\Study\Actions\ListStudyExportImportJobsAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\Study\StudyImportJobResource;
use App\Http\Support\AuthenticatedUser;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ListStudyExportImportJobsController extends Controller
{
    public function __invoke(
        Request $request,
        ListStudyExportImportJobsAction $listStudyExportImportJobs,
    ): AnonymousResourceCollection {
        $userId = AuthenticatedUser::id($request);

        return StudyImportJobResource::collection(
            $listStudyExportImportJobs->handle($userId),
        );
    }
}
