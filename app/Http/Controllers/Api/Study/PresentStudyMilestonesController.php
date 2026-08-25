<?php

namespace App\Http\Controllers\Api\Study;

use App\Domain\Study\Actions\PresentStudyMilestonesAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Study\PresentStudyMilestonesRequest;
use App\Http\Support\AuthenticatedUser;
use Illuminate\Http\Response;

class PresentStudyMilestonesController extends Controller
{
    public function __invoke(
        PresentStudyMilestonesRequest $request,
        PresentStudyMilestonesAction $presentStudyMilestones,
    ): Response {
        $presentStudyMilestones->handle(
            AuthenticatedUser::id($request),
            $request->milestoneKeys(),
        );

        return response()->noContent();
    }
}
