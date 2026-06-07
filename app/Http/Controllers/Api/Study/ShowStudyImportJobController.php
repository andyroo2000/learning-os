<?php

namespace App\Http\Controllers\Api\Study;

use App\Domain\Study\Actions\ShowStudyImportJobAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\Study\StudyImportJobResource;
use App\Http\Support\AuthenticatedUser;
use Illuminate\Http\Request;

class ShowStudyImportJobController extends Controller
{
    public function __invoke(
        Request $request,
        string $studyImportJobId,
        ShowStudyImportJobAction $showStudyImportJob,
    ): StudyImportJobResource {
        $userId = AuthenticatedUser::id($request);

        return StudyImportJobResource::make(
            $showStudyImportJob->handle($userId, $studyImportJobId),
        );
    }
}
