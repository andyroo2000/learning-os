<?php

use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Support\NewCardQueueReorderRateLimiter;
use App\Domain\Study\Support\StudyCompatibilityTrafficRateLimiter;
use App\Http\Controllers\Api\Study\ListStudyCardBatchController;
use App\Http\Controllers\Api\Study\ListStudyCardsController;
use App\Http\Controllers\Api\Study\ListStudyNewCardQueueController;
use App\Http\Controllers\Api\Study\ReorderStudyNewCardQueueController;
use App\Http\Controllers\Api\Study\ShowStudyCardController;
use Illuminate\Support\Facades\Route;

return static function (): void {
    Route::get('/study/new-queue', ListStudyNewCardQueueController::class)
        ->middleware('throttle:'.StudyCompatibilityTrafficRateLimiter::READ_NAME);
    Route::get('/study/cards', ListStudyCardsController::class)
        ->middleware('throttle:'.StudyCompatibilityTrafficRateLimiter::READ_NAME);
    // Sync clients resolve one feed page per request instead of spending the
    // shared compatibility quota on one card-detail request per feed entry.
    Route::post('/study/cards/batch', ListStudyCardBatchController::class)
        ->middleware('throttle:'.StudyCompatibilityTrafficRateLimiter::READ_NAME);
    Route::get('/study/cards/{cardId}', ShowStudyCardController::class)
        ->where('cardId', Card::CLIENT_ID_ROUTE_PATTERN)
        ->middleware('throttle:'.StudyCompatibilityTrafficRateLimiter::READ_NAME);
    // Shares the canonical new-card queue reorder rate limit above.
    Route::post('/study/new-queue/reorder', ReorderStudyNewCardQueueController::class)
        ->middleware('throttle:'.NewCardQueueReorderRateLimiter::NAME);
};
