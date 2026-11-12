<?php

use App\Http\Controllers\Api\Journal\JournalAnalysisController;
use App\Http\Controllers\Api\Journal\JournalAudioController;
use App\Http\Controllers\Api\Journal\JournalEntryController;
use Illuminate\Support\Facades\Route;

return static function (): void {
    Route::get('journal/voices', [JournalAudioController::class, 'voices'])->middleware('throttle:120,1,journal');
    Route::prefix('journal/entries')->middleware('throttle:120,1,journal')->group(function (): void {
        Route::get('/', [JournalEntryController::class, 'index']);
        Route::get('{entry}', [JournalEntryController::class, 'show'])->whereUlid('entry');
        Route::put('{entryId}', [JournalEntryController::class, 'save'])->whereUlid('entryId');
        Route::delete('{entryId}', [JournalEntryController::class, 'destroy'])->whereUlid('entryId');
        Route::post('{entryId}/submit', [JournalAnalysisController::class, 'submit'])->whereUlid('entryId')->middleware('throttle:3,1,journal-submit');
        Route::post('{entry}/audio', [JournalAudioController::class, 'audio'])->whereUlid('entry');
        Route::get('{entry}/analysis', [JournalAnalysisController::class, 'show'])->whereUlid('entry');
    });
};
