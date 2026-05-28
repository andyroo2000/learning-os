<?php

use App\Http\Controllers\Api\Flashcards\StoreCardController;
use App\Http\Controllers\Api\Flashcards\StoreDeckController;
use App\Http\Controllers\Api\Media\AttachMediaToCardController;
use App\Http\Controllers\Api\Reviews\StoreCardReviewEventBatchController;
use App\Http\Controllers\Api\Reviews\StoreCardReviewEventController;
use Illuminate\Support\Facades\Route;

// TODO: Move these API routes behind auth middleware before public exposure.
Route::post('/card-review-events/batch', StoreCardReviewEventBatchController::class);
Route::post('/card-review-events', StoreCardReviewEventController::class);
Route::post('/cards/{card}/media-assets', AttachMediaToCardController::class);
Route::post('/cards', StoreCardController::class);
Route::post('/decks', StoreDeckController::class);
