<?php

namespace App\Http\Controllers\Api\Study;

use App\Domain\Study\Actions\ListStudyExportReviewEventsAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\Reviews\CardReviewEventResource;
use App\Http\Support\AuthenticatedUser;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ListStudyExportReviewEventsController extends Controller
{
    public function __invoke(
        Request $request,
        ListStudyExportReviewEventsAction $listStudyExportReviewEvents,
    ): AnonymousResourceCollection {
        $userId = AuthenticatedUser::id($request);

        return CardReviewEventResource::collection(
            $listStudyExportReviewEvents->handle($userId),
        );
    }
}
