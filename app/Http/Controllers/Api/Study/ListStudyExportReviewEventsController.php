<?php

namespace App\Http\Controllers\Api\Study;

use App\Domain\Study\Actions\ListStudyExportReviewEventsAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\Reviews\CardReviewEventResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ListStudyExportReviewEventsController extends Controller
{
    public function __invoke(
        Request $request,
        ListStudyExportReviewEventsAction $listStudyExportReviewEvents,
    ): AnonymousResourceCollection {
        /** @var User $user */
        $user = $request->user();

        return CardReviewEventResource::collection(
            $listStudyExportReviewEvents->handle($user->id),
        );
    }
}
