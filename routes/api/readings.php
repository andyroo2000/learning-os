<?php

use App\Http\Controllers\Api\Readings\ReadingBookController;
use App\Http\Controllers\Api\Readings\ReadingController;
use App\Http\Controllers\Api\Readings\ReadingImportController;
use App\Http\Controllers\Api\Readings\ReadingLibraryController;
use App\Http\Controllers\Api\Readings\ReadingNarrationController;
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
    Route::get('/reading-library', ReadingLibraryController::class)->middleware('throttle:120,1');
    Route::post('/reading-imports', ReadingImportController::class)->middleware('throttle:20,1');
    Route::prefix('/reading-books')->middleware('throttle:120,1')->group(function (): void {
        Route::get('/', [ReadingBookController::class, 'index']);
        Route::post('/', [ReadingBookController::class, 'store']);
        Route::get('/{book}', [ReadingBookController::class, 'show'])->whereUlid('book');
        Route::patch('/{book}', [ReadingBookController::class, 'update'])->whereUlid('book');
        Route::put('/{book}/contents', [ReadingBookController::class, 'contents'])->whereUlid('book');
    });
    Route::patch('/convolab/readings/{reading}/narration', ReadingNarrationController::class)->whereUlid('reading')->middleware('throttle:120,1');
};
