<?php

use App\Http\Controllers\Api\Flashcards\StoreDeckController;
use Illuminate\Support\Facades\Route;

Route::post('/decks', StoreDeckController::class);
