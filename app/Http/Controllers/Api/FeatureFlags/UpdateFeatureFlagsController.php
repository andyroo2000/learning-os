<?php

namespace App\Http\Controllers\Api\FeatureFlags;

use App\Domain\FeatureFlags\Actions\UpdateFeatureFlagsAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\FeatureFlags\UpdateFeatureFlagsRequest;
use App\Http\Resources\FeatureFlags\FeatureFlagResource;
use Illuminate\Http\JsonResponse;

class UpdateFeatureFlagsController extends Controller
{
    public function __invoke(
        UpdateFeatureFlagsRequest $request,
        UpdateFeatureFlagsAction $updateFeatureFlags,
    ): JsonResponse {
        return response()->json(
            FeatureFlagResource::make($updateFeatureFlags->handle($request->validated()))
                ->resolve($request),
        );
    }
}
