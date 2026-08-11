<?php

use App\Domain\Flashcards\Support\DeckRateLimiter;
use App\Http\Controllers\Api\Flashcards\DeleteDeckController;
use App\Http\Controllers\Api\Flashcards\ListDeckCardsController;
use App\Http\Controllers\Api\Flashcards\ListDecksController;
use App\Http\Controllers\Api\Flashcards\ShowDeckController;
use App\Http\Controllers\Api\Flashcards\StoreDeckController;
use App\Http\Controllers\Api\Flashcards\UpdateDeckController;
use App\Http\Controllers\Api\Media\ListDeckMediaAssetsController;
use Illuminate\Support\Facades\Route;

return static function (): void {
    // Deck updates and deletes use separate buckets from deck creation so replay pressure cannot starve deletes.
    Route::prefix('/decks/{deck}')
        ->whereUlid('deck')
        ->group(function (): void {
            Route::get('/', ShowDeckController::class);
            Route::get('/media-assets', ListDeckMediaAssetsController::class);
            Route::get('/cards', ListDeckCardsController::class);
            Route::put('/', UpdateDeckController::class)
                ->middleware('throttle:'.DeckRateLimiter::UPDATE_NAME);
            Route::delete('/', DeleteDeckController::class)
                ->middleware('throttle:'.DeckRateLimiter::DELETE_NAME);
        });
    Route::get('/decks', ListDecksController::class);
    // Deck creation has its own retryable write bucket; idempotent de-dupe runs inside the action.
    Route::post('/decks', StoreDeckController::class)
        ->middleware('throttle:'.DeckRateLimiter::CREATE_NAME);
};
