<?php

use App\Http\Controllers\Api\Flashcards\ListDeckCardsController;
use App\Http\Controllers\Api\Flashcards\ListDecksController;
use App\Http\Controllers\Api\Flashcards\StoreCardController;
use App\Http\Controllers\Api\Flashcards\StoreDeckController;
use App\Http\Controllers\Api\Media\AttachMediaToCardController;
use App\Http\Controllers\Api\Media\ListCardMediaAssetsController;
use App\Http\Controllers\Api\Reviews\StoreCardReviewEventBatchController;
use App\Http\Controllers\Api\Reviews\StoreCardReviewEventController;
use Illuminate\Support\Facades\Route;

// Sanctum supports first-party sessions now and bearer tokens for mobile clients later.
Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('/card-review-events/batch', StoreCardReviewEventBatchController::class);
    Route::post('/card-review-events', StoreCardReviewEventController::class);
    Route::get('/cards/{card}/media-assets', ListCardMediaAssetsController::class);
    Route::post('/cards/{card}/media-assets', AttachMediaToCardController::class);
    Route::post('/cards', StoreCardController::class);
    Route::get('/decks/{deck}/cards', ListDeckCardsController::class);
    Route::get('/decks', ListDecksController::class);
    Route::post('/decks', StoreDeckController::class);
});
