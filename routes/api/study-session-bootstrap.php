<?php

use App\Domain\Study\Support\StudySessionStartRateLimiter;
use App\Http\Controllers\Api\Study\BuildStudyOfflineReserveController;
use App\Http\Controllers\Api\Study\StartStudyLessonController;
use App\Http\Controllers\Api\Study\StartStudySessionController;
use Illuminate\Support\Facades\Route;

return static function (): void {
    Route::post('/study/session/start', StartStudySessionController::class)
        ->middleware('throttle:'.StudySessionStartRateLimiter::NAME);
    Route::post('/study/lessons/start', StartStudyLessonController::class)
        ->middleware('throttle:'.StudySessionStartRateLimiter::NAME);
    Route::post('/study/offline-reserve', BuildStudyOfflineReserveController::class)
        ->middleware('throttle:'.StudySessionStartRateLimiter::NAME);
};
