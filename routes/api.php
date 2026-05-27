<?php

use App\Http\Controllers\Api\Flashcards\StoreCardController;
use App\Http\Controllers\Api\Flashcards\StoreDeckController;
use Illuminate\Support\Facades\Route;

Route::post('/cards', StoreCardController::class);
Route::post('/decks', StoreDeckController::class);
