<?php

namespace App\Http\Controllers\Api\Study;

use App\Domain\Study\Models\StudyActivitySession;
use App\Http\Controllers\Controller;
use App\Http\Requests\Study\ListStudyActivitySessionsRequest;
use App\Http\Resources\Study\StudyActivitySessionResource;
use App\Http\Support\AuthenticatedUser;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

class ListStudyActivitySessionsController extends Controller
{
    public function __invoke(ListStudyActivitySessionsRequest $request): JsonResponse
    {
        $data = $request->validated();
        $sessions = StudyActivitySession::query()
            ->where('user_id', AuthenticatedUser::id($request))
            ->where('started_at', '<', CarbonImmutable::parse($data['to']))
            ->where('ended_at', '>', CarbonImmutable::parse($data['from']))
            ->orderBy('started_at')
            ->get();

        return response()->json(StudyActivitySessionResource::collection($sessions)->resolve($request));
    }
}
