<?php

namespace App\Http\Controllers\Api\Study;

use App\Domain\Study\Actions\ListStudyImportJobsAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Study\ListStudyImportJobsRequest;
use App\Http\Resources\Study\StudyImportJobResource;
use App\Models\User;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ListStudyImportJobsController extends Controller
{
    public function __invoke(
        ListStudyImportJobsRequest $request,
        ListStudyImportJobsAction $listStudyImportJobs,
    ): AnonymousResourceCollection {
        /** @var User $user */
        $user = $request->user();

        return StudyImportJobResource::collection(
            $listStudyImportJobs->handle(
                userId: $user->id,
                pageSize: $request->pageSize(),
            )->withQueryString()
        );
    }
}
