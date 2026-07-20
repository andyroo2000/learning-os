<?php

namespace App\Http\Controllers\Api\FeatureFlags;

use App\Domain\FeatureFlags\Actions\GetFeatureFlagsAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\FeatureFlags\FeatureFlagResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShowFeatureFlagsController extends Controller
{
    public function __invoke(Request $request, GetFeatureFlagsAction $getFeatureFlags): JsonResponse
    {
        return response()->json(
            FeatureFlagResource::make($getFeatureFlags->handle())->resolve($request),
        );
    }
}
