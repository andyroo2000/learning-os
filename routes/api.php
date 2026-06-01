<?php

use App\Http\Controllers\Api\Flashcards\DeleteCardController;
use App\Http\Controllers\Api\Flashcards\ListDeckCardsController;
use App\Http\Controllers\Api\Flashcards\ListDecksController;
use App\Http\Controllers\Api\Flashcards\StoreCardController;
use App\Http\Controllers\Api\Flashcards\StoreDeckController;
use App\Http\Controllers\Api\Flashcards\UpdateCardController;
use App\Http\Controllers\Api\Flashcards\UpdateDeckController;
use App\Http\Controllers\Api\Media\AttachMediaToCardController;
use App\Http\Controllers\Api\Media\DeleteMediaAssetController;
use App\Http\Controllers\Api\Media\DetachMediaFromCardController;
use App\Http\Controllers\Api\Media\ListCardMediaAssetsController;
use App\Http\Controllers\Api\Media\ListDeckMediaAssetsController;
use App\Http\Controllers\Api\Media\ListMediaAssetsController;
use App\Http\Controllers\Api\Media\StoreMediaAssetController;
use App\Http\Controllers\Api\Reviews\ListCardReviewEventsController;
use App\Http\Controllers\Api\Reviews\StoreCardReviewEventBatchController;
use App\Http\Controllers\Api\Reviews\StoreCardReviewEventController;
use Illuminate\Support\Facades\Route;

// Sanctum supports first-party sessions now and bearer tokens for mobile clients later.
Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('/card-review-events/batch', StoreCardReviewEventBatchController::class);
    Route::post('/card-review-events', StoreCardReviewEventController::class);
    Route::get('/cards/{card}/review-events', ListCardReviewEventsController::class)->whereUlid('card');
    Route::get('/cards/{card}/media-assets', ListCardMediaAssetsController::class)->whereUlid('card');
    Route::post('/cards/{card}/media-assets', AttachMediaToCardController::class)->whereUlid('card');
    Route::delete('/cards/{card}/media-assets/{mediaAsset}', DetachMediaFromCardController::class)
        ->whereUlid('card')
        ->whereUlid('mediaAsset');
    Route::put('/cards/{card}', UpdateCardController::class)->whereUlid('card');
    Route::delete('/cards/{card}', DeleteCardController::class)->whereUlid('card');
    Route::post('/cards', StoreCardController::class);
    Route::get('/media-assets', ListMediaAssetsController::class);
    Route::post('/media-assets', StoreMediaAssetController::class);
    // Use a raw ID segment so missing/cross-user media assets stay idempotent 204s.
    Route::delete('/media-assets/{mediaAssetId}', DeleteMediaAssetController::class);
    Route::prefix('/decks/{deck}')
        ->whereUlid('deck')
        ->group(function (): void {
            Route::get('/media-assets', ListDeckMediaAssetsController::class);
            Route::get('/cards', ListDeckCardsController::class);
            Route::put('/', UpdateDeckController::class);
        });
    Route::get('/decks', ListDecksController::class);
    Route::post('/decks', StoreDeckController::class);
});
