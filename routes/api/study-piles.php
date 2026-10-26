<?php

use App\Domain\Study\Support\StudyCompatibilityTrafficRateLimiter;
use App\Domain\Study\Support\StudyPileWriteRateLimiter;
use App\Http\Controllers\Api\Study\StudyPileController;
use Illuminate\Support\Facades\Route;

return static function (): void {
    Route::get('/study/piles', [StudyPileController::class, 'index'])
        ->middleware('throttle:'.StudyCompatibilityTrafficRateLimiter::READ_NAME);
    Route::put('/study/piles/{pileId}', [StudyPileController::class, 'save'])
        ->whereUlid('pileId')
        ->middleware('throttle:'.StudyPileWriteRateLimiter::NAME);
    Route::put('/study/piles/{pileId}/chapters/{chapterId}', [StudyPileController::class, 'saveChapter'])
        ->whereUlid('pileId')->whereUlid('chapterId')
        ->middleware('throttle:'.StudyPileWriteRateLimiter::NAME);
};
