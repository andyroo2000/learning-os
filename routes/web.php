<?php

use App\Domain\Auth\Support\ConvoLabOAuthRateLimiter;
use App\Domain\Calendar\Support\GoogleCalendarConnectionRateLimiter;
use App\Domain\Study\Support\StudyCompatibilityTrafficRateLimiter;
use App\Http\Controllers\Web\Auth\BeginConvoLabGoogleOAuthController;
use App\Http\Controllers\Web\Auth\CompleteConvoLabGoogleOAuthController;
use App\Http\Controllers\Web\Calendar\BeginGoogleCalendarOAuthController;
use App\Http\Controllers\Web\Calendar\CompleteGoogleCalendarOAuthController;
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

// Browser-only OAuth: native clients need the separate single-use connect-intent flow.
Route::get('/api/study/google-calendar/connect', BeginGoogleCalendarOAuthController::class)->middleware([
    'auth:web',
    'throttle:'.StudyCompatibilityTrafficRateLimiter::NETWORK_NAME,
    'throttle:'.StudyCompatibilityTrafficRateLimiter::READ_NAME,
    'throttle:'.GoogleCalendarConnectionRateLimiter::OAUTH_BEGIN,
]);
Route::get('/api/study/google-calendar/callback', CompleteGoogleCalendarOAuthController::class)
    ->middleware([
        'throttle:'.StudyCompatibilityTrafficRateLimiter::NETWORK_NAME,
        'throttle:'.StudyCompatibilityTrafficRateLimiter::READ_NAME,
        'throttle:'.GoogleCalendarConnectionRateLimiter::OAUTH_CALLBACK,
    ]);
