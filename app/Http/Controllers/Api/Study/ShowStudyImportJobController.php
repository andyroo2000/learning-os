<?php

namespace App\Http\Controllers\Api\Study;

use App\Domain\Study\Actions\ShowStudyImportJobAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\Study\StudyImportJobResource;
use App\Models\User;
use Illuminate\Http\Request;

class ShowStudyImportJobController extends Controller
{
    public function __invoke(
        Request $request,
        string $studyImportJobId,
        ShowStudyImportJobAction $showStudyImportJob,
    ): StudyImportJobResource {
        /** @var User $user */
        $user = $request->user();

        return StudyImportJobResource::make(
            $showStudyImportJob->handle($user->id, $studyImportJobId),
        );
    }
}
