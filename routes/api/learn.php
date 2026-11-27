<?php

use App\Http\Controllers\Api\Learn\LearnController;
use App\Http\Middleware\EnsureLearnEnabled;
use Illuminate\Support\Facades\Route;

return static function (): void {
    Route::prefix('learn')->middleware([EnsureLearnEnabled::class, 'throttle:120,1,learn'])->group(function (): void {
        Route::get('lessons', [LearnController::class, 'index']);
        Route::post('lessons/{lessonId}/session', [LearnController::class, 'start'])->middleware('throttle:20,1,learn-start');
        Route::get('sessions/{session}', [LearnController::class, 'show'])->whereUlid('session');
        Route::post('sessions/{session}/turn', [LearnController::class, 'respond'])->whereUlid('session')->middleware('throttle:12,1,learn-turn');
    });
};
