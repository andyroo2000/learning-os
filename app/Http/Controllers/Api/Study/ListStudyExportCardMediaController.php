<?php

namespace App\Http\Controllers\Api\Study;

use App\Domain\Study\Actions\ListStudyExportCardMediaAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\Study\StudyExportCardMediaResource;
use App\Http\Support\AuthenticatedUser;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ListStudyExportCardMediaController extends Controller
{
    public function __invoke(
        Request $request,
        ListStudyExportCardMediaAction $listStudyExportCardMedia,
    ): AnonymousResourceCollection {
        $userId = AuthenticatedUser::id($request);

        return StudyExportCardMediaResource::collection(
            $listStudyExportCardMedia->handle($userId),
        );
    }
}
