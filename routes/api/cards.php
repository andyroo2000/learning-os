<?php

use App\Domain\Flashcards\Support\NewCardQueueReorderRateLimiter;
use App\Domain\Media\Support\CardMediaRateLimiter;
use App\Domain\Study\Support\StudyCardActionRateLimiter;
use App\Domain\Study\Support\StudyCardCreateRateLimiter;
use App\Domain\Study\Support\StudyCardDeleteRateLimiter;
use App\Domain\Study\Support\StudyCardUpdateRateLimiter;
use App\Http\Controllers\Api\Flashcards\DeleteCardController;
use App\Http\Controllers\Api\Flashcards\ListCardsController;
use App\Http\Controllers\Api\Flashcards\ListDueCardsController;
use App\Http\Controllers\Api\Flashcards\ListNewCardsController;
use App\Http\Controllers\Api\Flashcards\PerformCardStudyActionController;
use App\Http\Controllers\Api\Flashcards\ReorderNewCardQueueController;
use App\Http\Controllers\Api\Flashcards\ShowCardController;
use App\Http\Controllers\Api\Flashcards\StoreCardController;
use App\Http\Controllers\Api\Flashcards\UpdateCardController;
use App\Http\Controllers\Api\Flashcards\UpdateCardStudyStatusController;
use App\Http\Controllers\Api\Media\AttachMediaToCardController;
use App\Http\Controllers\Api\Media\DetachMediaFromCardController;
use App\Http\Controllers\Api\Media\ListCardMediaAssetsController;
use App\Http\Controllers\Api\Reviews\ListCardReviewEventsController;
use Illuminate\Support\Facades\Route;

return static function (): void {
    Route::get('/cards/due', ListDueCardsController::class);
    Route::get('/cards/new', ListNewCardsController::class);
    // Canonical and ConvoLab queue reorders share one user-scoped rate-limit bucket.
    Route::post('/cards/new/reorder', ReorderNewCardQueueController::class)
        ->middleware('throttle:'.NewCardQueueReorderRateLimiter::NAME);
    Route::get('/cards/{card}', ShowCardController::class)->whereUlid('card');
    Route::get('/cards/{card}/review-events', ListCardReviewEventsController::class)->whereUlid('card');
    Route::get('/cards/{card}/media-assets', ListCardMediaAssetsController::class)->whereUlid('card');
    // Card-media relation writes have their own retry-friendly rate limits.
    Route::post('/cards/{card}/media-assets', AttachMediaToCardController::class)
        ->whereUlid('card')
        ->middleware('throttle:'.CardMediaRateLimiter::ATTACH_NAME);
    Route::delete('/cards/{card}/media-assets/{mediaAsset}', DetachMediaFromCardController::class)
        ->whereUlid('card')
        ->whereUlid('mediaAsset')
        ->middleware('throttle:'.CardMediaRateLimiter::DETACH_NAME);
    Route::get('/cards', ListCardsController::class);
    // Canonical and study card writes share rate limits because they mutate the same resources.
    Route::post('/cards', StoreCardController::class)
        ->middleware('throttle:'.StudyCardCreateRateLimiter::NAME);
    Route::post('/cards/{card}/actions', PerformCardStudyActionController::class)
        ->whereUlid('card')
        ->middleware('throttle:'.StudyCardActionRateLimiter::NAME);
    Route::patch('/cards/{card}/study-status', UpdateCardStudyStatusController::class)
        ->whereUlid('card')
        ->middleware('throttle:'.StudyCardUpdateRateLimiter::NAME);
    Route::put('/cards/{card}', UpdateCardController::class)
        ->whereUlid('card')
        ->middleware('throttle:'.StudyCardUpdateRateLimiter::NAME);
    Route::delete('/cards/{card}', DeleteCardController::class)
        ->whereUlid('card')
        ->middleware('throttle:'.StudyCardDeleteRateLimiter::NAME);
};
