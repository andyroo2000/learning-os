<?php

namespace App\Http\Controllers\Api\Study;

use App\Domain\Study\Actions\ListStudyExportImportJobsAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\Study\StudyImportJobResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ListStudyExportImportJobsController extends Controller
{
    public function __invoke(
        Request $request,
        ListStudyExportImportJobsAction $listStudyExportImportJobs,
    ): AnonymousResourceCollection {
        /** @var User $user */
        $user = $request->user();

        return StudyImportJobResource::collection(
            $listStudyExportImportJobs->handle($user->id),
        );
    }
}
