<?php

use App\Http\Controllers\Api\Journal\JournalAnalysisController;
use App\Http\Controllers\Api\Journal\JournalAudioController;
use App\Http\Controllers\Api\Journal\JournalDocumentController;
use App\Http\Controllers\Api\Journal\JournalEntryController;
use App\Http\Controllers\Api\Journal\JournalReplyController;
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
        Route::get('{entry}/audio', [JournalAudioController::class, 'state'])->whereUlid('entry');
        Route::put('{entry}/replies/{replyId}', [JournalReplyController::class, 'save'])->whereUlid('entry')->whereUlid('replyId')->middleware('throttle:3,1,journal-reply');
        Route::get('{entry}/replies/{replyId}', [JournalReplyController::class, 'show'])->whereUlid('entry')->whereUlid('replyId');
        Route::put('{entry}/suggestion', [JournalDocumentController::class, 'suggestion'])->whereUlid('entry');
        Route::post('{entry}/undo', [JournalReplyController::class, 'undo'])->whereUlid('entry');
        Route::get('{entry}/analysis', [JournalAnalysisController::class, 'show'])->whereUlid('entry');
    });
};
