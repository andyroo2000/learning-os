<?php

namespace App\Http\Controllers\Api\Study;

use App\Domain\Study\Actions\UpsertStudyActivitySessionsAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Study\StoreStudyActivitySessionsRequest;
use App\Http\Resources\Study\StudyActivitySessionResource;
use App\Http\Support\AuthenticatedUser;
use Illuminate\Http\JsonResponse;

class StoreStudyActivitySessionsController extends Controller
{
    public function __invoke(
        StoreStudyActivitySessionsRequest $request,
        UpsertStudyActivitySessionsAction $upsert,
    ): JsonResponse {
        $sessions = $upsert->handle(
            AuthenticatedUser::id($request),
            $request->sessions(),
        );

        return response()->json(StudyActivitySessionResource::collection($sessions)->resolve($request));
    }
}
