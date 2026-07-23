<?php

use App\Domain\Auth\Support\ConvoLabOAuthRateLimiter;
use App\Http\Controllers\Web\Auth\BeginConvoLabGoogleOAuthController;
use App\Http\Controllers\Web\Auth\CompleteConvoLabGoogleOAuthController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get(
    '/api/convolab/browser/auth/google',
    BeginConvoLabGoogleOAuthController::class,
)->middleware('throttle:'.ConvoLabOAuthRateLimiter::BROWSER_START);
Route::get(
    '/api/convolab/browser/auth/google/callback',
    CompleteConvoLabGoogleOAuthController::class,
)->middleware('throttle:'.ConvoLabOAuthRateLimiter::BROWSER_CALLBACK);
