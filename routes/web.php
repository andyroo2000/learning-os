<?php

use App\Domain\Auth\Support\ConvoLabOAuthRateLimiter;
use App\Http\Controllers\Web\Auth\BeginConvoLabGoogleOAuthController;
use App\Http\Controllers\Web\Auth\CompleteConvoLabGoogleOAuthController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Socialite's full-page redirect and callback need the web session for OAuth state.
Route::get(
    '/api/convolab/browser/auth/google',
    BeginConvoLabGoogleOAuthController::class,
)->middleware('throttle:'.ConvoLabOAuthRateLimiter::BROWSER_START);
Route::get(
    '/api/convolab/browser/auth/google/callback',
    CompleteConvoLabGoogleOAuthController::class,
)->middleware('throttle:'.ConvoLabOAuthRateLimiter::BROWSER_CALLBACK);
