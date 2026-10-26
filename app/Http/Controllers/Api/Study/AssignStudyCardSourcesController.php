<?php

namespace App\Http\Controllers\Api\Study;

use App\Domain\Study\Actions\AssignStudyCardSourcesAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Study\AssignStudyCardSourcesRequest;
use App\Http\Support\AuthenticatedUser;
use Illuminate\Http\JsonResponse;

class AssignStudyCardSourcesController extends Controller
{
    public function __invoke(AssignStudyCardSourcesRequest $request, AssignStudyCardSourcesAction $assign): JsonResponse
    {
        $changed = $assign->handle(
            AuthenticatedUser::id($request),
            $request->validated('cardIds'),
            $request->validated('bookSource'),
        );

        return response()->json(['changed' => $changed]);
    }
}
