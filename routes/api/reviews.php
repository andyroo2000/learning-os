<?php

use App\Domain\Reviews\Support\CardReviewEventCreateRateLimiter;
use App\Domain\Reviews\Support\CardReviewEventUndoRateLimiter;
use App\Http\Controllers\Api\Reviews\ListReviewEventsController;
use App\Http\Controllers\Api\Reviews\ShowCardReviewEventController;
use App\Http\Controllers\Api\Reviews\StoreCardReviewEventBatchController;
use App\Http\Controllers\Api\Reviews\StoreCardReviewEventController;
use App\Http\Controllers\Api\Reviews\UndoCardReviewEventController;
use Illuminate\Support\Facades\Route;

return static function (): void {
    Route::get('/card-review-events', ListReviewEventsController::class);
    // Review creates, batch replay, and study create aliases share one request-rate bucket.
    // Batch payload size remains capped at 500 events by request validation.
    Route::post('/card-review-events/batch', StoreCardReviewEventBatchController::class)
        ->middleware('throttle:'.CardReviewEventCreateRateLimiter::NAME);
    Route::get('/card-review-events/{cardReviewEvent}', ShowCardReviewEventController::class)->whereUlid('cardReviewEvent');
    // Review undo hard-deletes the event; DELETE retries for already-undone events resolve as 404.
    Route::delete('/card-review-events/{cardReviewEvent}', UndoCardReviewEventController::class)
        ->whereUlid('cardReviewEvent')
        ->middleware('throttle:'.CardReviewEventUndoRateLimiter::NAME);
    Route::post('/card-review-events', StoreCardReviewEventController::class)
        ->middleware('throttle:'.CardReviewEventCreateRateLimiter::NAME);
};
