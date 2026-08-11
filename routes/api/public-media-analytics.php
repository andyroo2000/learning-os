<?php

use App\Domain\Analytics\Support\ToolAnalyticsRateLimiter;
use App\Domain\Media\Support\ToolAudioSignedUrlRateLimiter;
use App\Http\Controllers\Api\Analytics\StoreBrowserToolAnalyticsEventController;
use App\Http\Controllers\Api\Media\ResolveToolAudioUrlsController;
use App\Http\Controllers\Api\Media\ShowAvatarAssetController;
use Illuminate\Support\Facades\Route;

return static function (): void {
    // Public static learning media stays path-allowlisted and rate-limited where URLs are batched.
    Route::get('/avatars/{avatarPath}', ShowAvatarAssetController::class)
        ->where('avatarPath', '.*');
    Route::post('/tools-audio/signed-urls', ResolveToolAudioUrlsController::class)
        ->middleware('throttle:'.ToolAudioSignedUrlRateLimiter::NAME);
    Route::post(
        '/convolab/browser/tools/analytics',
        StoreBrowserToolAnalyticsEventController::class,
    )->middleware('throttle:'.ToolAnalyticsRateLimiter::BROWSER_NAME);
};
