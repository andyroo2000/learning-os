<?php

use App\Domain\Calendar\Support\GoogleCalendarConnectionRateLimiter;
use App\Domain\Study\Support\StudyCompatibilityTrafficRateLimiter;
use App\Http\Controllers\Api\Study\DisconnectGoogleCalendarController;
use App\Http\Controllers\Api\Study\ShowGoogleCalendarConnectionController;
use Illuminate\Support\Facades\Route;

return static function (): void {
    Route::get('/study/google-calendar', ShowGoogleCalendarConnectionController::class)
        ->middleware('throttle:'.StudyCompatibilityTrafficRateLimiter::READ_NAME);
    Route::delete('/study/google-calendar', DisconnectGoogleCalendarController::class)
        ->middleware('throttle:'.GoogleCalendarConnectionRateLimiter::NAME);
};
