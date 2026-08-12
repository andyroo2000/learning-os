<?php

use App\Domain\Study\Support\StudyCompatibilityTrafficRateLimiter;
use App\Http\Controllers\Api\Media\DownloadMediaAssetContentController;
use App\Http\Controllers\Api\Study\DownloadStudyMediaBatchController;
use Illuminate\Support\Facades\Route;

return static function (): void {
    Route::post('/study/media/batch', DownloadStudyMediaBatchController::class)
        ->middleware('throttle:'.StudyCompatibilityTrafficRateLimiter::MEDIA_NAME);
    Route::get('/study/media/{mediaAsset}', DownloadMediaAssetContentController::class)
        ->whereUlid('mediaAsset')
        ->middleware('throttle:'.StudyCompatibilityTrafficRateLimiter::MEDIA_NAME);
};
