<?php

namespace App\Http\Controllers\Api\FeatureFlags;

use App\Domain\Admin\Models\AdminUserProjection;
use App\Domain\FeatureFlags\Actions\GetFeatureFlagsAction;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ShowFeatureAvailabilityController extends Controller
{
    public function __invoke(Request $request, GetFeatureFlagsAction $getFlags): JsonResponse
    {
        $flags = $getFlags->handle();
        $admin = AdminUserProjection::where('user_id', $request->user()->id)->where('role', 'admin')->exists();

        return response()->json([
            'flashcardsEnabled' => $admin || $flags->flashcardsEnabled,
            'readingsEnabled' => $admin || $flags->readingsEnabled,
            'studyTimeEnabled' => $admin || $flags->studyTimeEnabled,
            'learnEnabled' => $admin || $flags->learnEnabled,
        ])->header('Cache-Control', 'private, no-store');
    }
}
