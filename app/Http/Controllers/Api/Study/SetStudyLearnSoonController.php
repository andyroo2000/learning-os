<?php

namespace App\Http\Controllers\Api\Study;

use App\Domain\Study\Actions\SetStudyLearnSoonAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Study\SetStudyLearnSoonRequest;
use App\Http\Support\AuthenticatedUser;
use Illuminate\Http\JsonResponse;

class SetStudyLearnSoonController extends Controller
{
    public function __invoke(SetStudyLearnSoonRequest $request, SetStudyLearnSoonAction $set): JsonResponse
    {
        return response()->json(['changed' => $set->handle(
            AuthenticatedUser::id($request), $request->validated('cardIds'), $request->boolean('learnSoon'),
        )]);
    }
}
