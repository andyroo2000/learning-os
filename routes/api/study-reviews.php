<?php

use App\Domain\Reviews\Support\CardReviewEventCreateRateLimiter;
use App\Domain\Reviews\Support\CardReviewEventUndoRateLimiter;
use App\Http\Controllers\Api\Study\StoreStudyReviewController;
use App\Http\Controllers\Api\Study\StoreStudyReviewUndoController;
use App\Http\Controllers\Api\Study\UndoStudyReviewController;
use Illuminate\Support\Facades\Route;

return static function (): void {
    // Shares the canonical review-create rate limit above.
    Route::post('/study/reviews', StoreStudyReviewController::class)
        ->middleware('throttle:'.CardReviewEventCreateRateLimiter::NAME);
    // Shares the canonical review-undo rate limit above, separate from review creates.
    Route::post('/study/reviews/undo', StoreStudyReviewUndoController::class)
        ->middleware('throttle:'.CardReviewEventUndoRateLimiter::NAME);
    Route::delete('/study/reviews/{reviewLogId}', UndoStudyReviewController::class)
        ->whereUlid('reviewLogId')
        ->middleware('throttle:'.CardReviewEventUndoRateLimiter::NAME);
};
