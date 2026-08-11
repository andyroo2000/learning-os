<?php

use App\Domain\Study\Support\StudyCompatibilityTrafficRateLimiter;
use App\Http\Controllers\Api\Study\ListStudyBrowserController;
use App\Http\Controllers\Api\Study\ShowStudyBrowserNoteController;
use Illuminate\Support\Facades\Route;

return static function (): void {
    Route::get('/study/browser', ListStudyBrowserController::class)
        ->middleware('throttle:'.StudyCompatibilityTrafficRateLimiter::READ_NAME);
    // Supports numeric Anki IDs, native ULIDs, and copied ConvoLab UUIDs.
    Route::get('/study/browser/{noteId}', ShowStudyBrowserNoteController::class)
        ->where('noteId', '[A-Za-z0-9-]+')
        ->middleware('throttle:'.StudyCompatibilityTrafficRateLimiter::READ_NAME);
};
