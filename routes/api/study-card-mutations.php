<?php

use App\Domain\Flashcards\Models\Card;
use App\Domain\Study\Support\StudyCardActionRateLimiter;
use App\Domain\Study\Support\StudyCardCreateRateLimiter;
use App\Domain\Study\Support\StudyCardDeleteRateLimiter;
use App\Domain\Study\Support\StudyCardUpdateRateLimiter;
use App\Http\Controllers\Api\Study\DeleteStudyCardController;
use App\Http\Controllers\Api\Study\PerformStudyCardActionController;
use App\Http\Controllers\Api\Study\StoreStudyCardController;
use App\Http\Controllers\Api\Study\UpdateStudyCardController;
use Illuminate\Support\Facades\Route;

return static function (): void {
    // Shares the study-card creation rate limit with draft creation and commits.
    Route::post('/study/cards', StoreStudyCardController::class)
        ->middleware('throttle:'.StudyCardCreateRateLimiter::NAME);
    Route::delete('/study/cards/{cardId}', DeleteStudyCardController::class)
        ->where('cardId', Card::CLIENT_ID_ROUTE_PATTERN)
        ->middleware('throttle:'.StudyCardDeleteRateLimiter::NAME);
    // Manual card actions can be retried independently from create/update/delete writes.
    Route::post('/study/cards/{cardId}/actions', PerformStudyCardActionController::class)
        ->where('cardId', Card::CLIENT_ID_ROUTE_PATTERN)
        ->middleware('throttle:'.StudyCardActionRateLimiter::NAME);
    // Saved-card edits can be retried by sync clients; keep their rate limit separate.
    Route::patch('/study/cards/{cardId}', UpdateStudyCardController::class)
        ->where('cardId', Card::CLIENT_ID_ROUTE_PATTERN)
        ->middleware('throttle:'.StudyCardUpdateRateLimiter::NAME);
};
