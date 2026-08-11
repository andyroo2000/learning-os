<?php

use App\Domain\Study\Support\DailyAudioPracticeGenerationRateLimiter;
use App\Domain\Study\Support\StudyCompatibilityTrafficRateLimiter;
use App\Http\Controllers\Api\Study\DownloadDailyAudioPracticeTrackController;
use App\Http\Controllers\Api\Study\ListDailyAudioPracticesController;
use App\Http\Controllers\Api\Study\ShowDailyAudioPracticeController;
use App\Http\Controllers\Api\Study\ShowDailyAudioPracticeStatusController;
use App\Http\Controllers\Api\Study\StoreDailyAudioPracticeController;
use Illuminate\Support\Facades\Route;

return static function (): void {
    Route::post('/daily-audio-practice', StoreDailyAudioPracticeController::class)
        ->middleware('throttle:'.DailyAudioPracticeGenerationRateLimiter::NAME);
    Route::get('/daily-audio-practice', ListDailyAudioPracticesController::class)
        ->middleware('throttle:'.StudyCompatibilityTrafficRateLimiter::READ_NAME);
    Route::get('/daily-audio-practice/{practiceId}', ShowDailyAudioPracticeController::class)
        ->whereUuid('practiceId')
        ->middleware('throttle:'.StudyCompatibilityTrafficRateLimiter::READ_NAME);
    Route::get('/daily-audio-practice/{practiceId}/status', ShowDailyAudioPracticeStatusController::class)
        ->whereUuid('practiceId')
        ->middleware('throttle:'.StudyCompatibilityTrafficRateLimiter::READ_NAME);
    Route::get(
        '/daily-audio-practice/{practiceId}/tracks/{trackId}/audio',
        DownloadDailyAudioPracticeTrackController::class,
    )
        ->whereUuid('practiceId')
        ->whereUuid('trackId')
        ->middleware('throttle:'.StudyCompatibilityTrafficRateLimiter::MEDIA_NAME);
};
