<?php

use App\Http\Controllers\Api\Readings\ReadingController;
use Illuminate\Support\Facades\Route;

return static function (): void {
    Route::prefix('/convolab/readings')->middleware('throttle:120,1')->group(function (): void {
        Route::get('/', [ReadingController::class, 'index']);
        Route::get('/{reading}', [ReadingController::class, 'show'])->whereUlid('reading');
        Route::get('/{reading}/illustration', [ReadingController::class, 'illustration'])->whereUlid('reading');
        Route::get('/{reading}/pages/{page}/illustration', [ReadingController::class, 'pageIllustration'])
            ->whereUlid('reading')->where('page', '[0-9]{1,5}');
        Route::post('/{reading}/sentences/{sentence}/audio', [ReadingController::class, 'audio'])
            ->whereUlid('reading')->where('sentence', '[0-9]{1,5}');
    });
};
