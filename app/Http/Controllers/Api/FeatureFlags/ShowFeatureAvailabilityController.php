<?php

namespace App\Http\Controllers\Api\FeatureFlags;

use App\Domain\FeatureFlags\Actions\GetEffectiveFeatureFlagsAction;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ShowFeatureAvailabilityController extends Controller
{
    public function __invoke(Request $request, GetEffectiveFeatureFlagsAction $getFlags): JsonResponse
    {
        return response()->json($getFlags->handle($request->user()->id))->header('Cache-Control', 'private, no-store');
    }
}
