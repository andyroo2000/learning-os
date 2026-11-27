<?php

namespace App\Http\Controllers\Api\FeatureFlags;

use App\Domain\FeatureFlags\Actions\SaveAccessGroupAction;
use App\Domain\FeatureFlags\Actions\SaveFeatureAudienceAction;
use App\Domain\FeatureFlags\Contracts\AudienceUserSearch;
use App\Domain\FeatureFlags\Services\FeatureAudienceReader;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ConvoLabAdminReadRequest;
use App\Http\Requests\FeatureFlags\SaveAccessGroupRequest;
use App\Http\Requests\FeatureFlags\SaveFeatureAudienceRequest;
use App\Http\Requests\FeatureFlags\SearchAudienceUsersRequest;
use Illuminate\Http\JsonResponse;

final class FeatureAudienceController extends Controller
{
    public function show(ConvoLabAdminReadRequest $request, string $feature, FeatureAudienceReader $reader): JsonResponse
    {
        return response()->json($reader->audience($feature))->header('Cache-Control', 'private, no-store');
    }

    public function save(SaveFeatureAudienceRequest $request, string $feature, SaveFeatureAudienceAction $action): JsonResponse
    {
        return response()->json($action->handle($feature, $request->validated()));
    }

    public function groups(ConvoLabAdminReadRequest $request, FeatureAudienceReader $reader): JsonResponse
    {
        return response()->json(['groups' => $reader->groups()])->header('Cache-Control', 'private, no-store');
    }

    public function createGroup(SaveAccessGroupRequest $request, SaveAccessGroupAction $action): JsonResponse
    {
        return response()->json($action->handle(null, $request->validated()), 201);
    }

    public function saveGroup(SaveAccessGroupRequest $request, string $group, SaveAccessGroupAction $action): JsonResponse
    {
        return response()->json($action->handle($group, $request->validated()));
    }

    public function search(SearchAudienceUsersRequest $request, AudienceUserSearch $search): JsonResponse
    {
        return response()->json($search->search($request->validated()))->header('Cache-Control', 'private, no-store');
    }
}
