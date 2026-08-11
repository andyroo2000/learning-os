<?php

use App\Domain\Study\Support\StudyActivitySessionRateLimiter;
use App\Domain\Study\Support\StudyCompatibilityTrafficRateLimiter;
use App\Http\Controllers\Api\Study\DeleteStudyActivitySessionController;
use App\Http\Controllers\Api\Study\ListStudyActivitySessionsController;
use App\Http\Controllers\Api\Study\ShowStudyActivityAnalyticsController;
use App\Http\Controllers\Api\Study\ShowStudyOverviewController;
use App\Http\Controllers\Api\Study\StoreStudyActivitySessionsController;
use Illuminate\Support\Facades\Route;

return static function (): void {
    Route::get('/study/overview', ShowStudyOverviewController::class)
        ->middleware('throttle:'.StudyCompatibilityTrafficRateLimiter::READ_NAME);
    Route::get('/study/activity-sessions', ListStudyActivitySessionsController::class)
        ->middleware('throttle:'.StudyCompatibilityTrafficRateLimiter::READ_NAME);
    Route::get('/study/activity-analytics', ShowStudyActivityAnalyticsController::class)
        ->middleware('throttle:'.StudyCompatibilityTrafficRateLimiter::READ_NAME);
    Route::post('/study/activity-sessions/batch', StoreStudyActivitySessionsController::class)
        ->middleware('throttle:'.StudyActivitySessionRateLimiter::NAME);
    Route::delete('/study/activity-sessions/{clientSessionId}', DeleteStudyActivitySessionController::class)
        ->where('clientSessionId', '(?:[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}|[0-9a-fA-F-]{36})')
        ->middleware('throttle:'.StudyActivitySessionRateLimiter::NAME);
};
