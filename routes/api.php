<?php

use App\Http\Controllers\Api\Flashcards\StoreCardController;
use App\Http\Controllers\Api\Flashcards\StoreDeckController;
use App\Http\Controllers\Api\Reviews\StoreCardReviewEventController;
use Illuminate\Support\Facades\Route;

Route::post('/card-review-events', StoreCardReviewEventController::class);
Route::post('/cards', StoreCardController::class);
Route::post('/decks', StoreDeckController::class);
