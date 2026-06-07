<?php

namespace App\Http\Controllers\Api\Study;

use App\Domain\Study\Actions\ListStudyImportJobsAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Study\ListStudyImportJobsRequest;
use App\Http\Resources\Study\StudyImportJobResource;
use App\Http\Support\AuthenticatedUser;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ListStudyImportJobsController extends Controller
{
    public function __invoke(
        ListStudyImportJobsRequest $request,
        ListStudyImportJobsAction $listStudyImportJobs,
    ): AnonymousResourceCollection {
        $userId = AuthenticatedUser::id($request);

        return StudyImportJobResource::collection(
            $listStudyImportJobs->handle(
                userId: $userId,
                pageSize: $request->pageSize(),
                status: $request->status(),
            )->withQueryString()
        );
    }
}
