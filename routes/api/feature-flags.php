<?php

use App\Domain\FeatureFlags\Support\FeatureFlagUpdateRateLimiter;
use App\Http\Controllers\Api\FeatureFlags\ShowFeatureAvailabilityController;
use App\Http\Controllers\Api\FeatureFlags\ShowFeatureFlagsController;
use App\Http\Controllers\Api\FeatureFlags\UpdateFeatureFlagsController;
use Illuminate\Support\Facades\Route;

return static function (): void {
    Route::get('/feature-flags', ShowFeatureFlagsController::class);
    Route::get('/convolab/feature-availability', ShowFeatureAvailabilityController::class);
    require __DIR__.'/feature-audiences.php';
    Route::patch('/feature-flags', UpdateFeatureFlagsController::class)
        ->middleware('throttle:'.FeatureFlagUpdateRateLimiter::NAME);
};
