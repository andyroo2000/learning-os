<?php

use App\Domain\Auth\Support\AuthAccountRateLimiter;
use App\Domain\Auth\Support\AuthEmailRateLimiter;
use App\Domain\Auth\Support\ConvoLabAccountSecurityRateLimiter;
use App\Domain\Auth\Support\ConvoLabOAuthRateLimiter;
use App\Domain\Auth\Support\ConvoLabProfileRateLimiter;
use App\Domain\Auth\Support\ConvoLabVerificationRateLimiter;
use App\Http\Controllers\Api\Auth\AuthenticateConvoLabBrowserUserController;
use App\Http\Controllers\Api\Auth\ClaimConvoLabBrowserGoogleInviteController;
use App\Http\Controllers\Api\Auth\DeleteConvoLabCurrentUserController;
use App\Http\Controllers\Api\Auth\DeleteCurrentUserController;
use App\Http\Controllers\Api\Auth\DestroyAccessTokenController;
use App\Http\Controllers\Api\Auth\DestroyConvoLabBrowserSessionController;
use App\Http\Controllers\Api\Auth\DestroyCurrentAccessTokenController;
use App\Http\Controllers\Api\Auth\DisconnectConvoLabGoogleIdentityController;
use App\Http\Controllers\Api\Auth\ListAccessTokensController;
use App\Http\Controllers\Api\Auth\RegisterConvoLabBrowserUserController;
use App\Http\Controllers\Api\Auth\RegisterConvoLabMobileUserController;
use App\Http\Controllers\Api\Auth\RegisterMobileUserController;
use App\Http\Controllers\Api\Auth\ResetUserPasswordController;
use App\Http\Controllers\Api\Auth\SendConvoLabBrowserVerificationController;
use App\Http\Controllers\Api\Auth\SendConvoLabVerificationController;
use App\Http\Controllers\Api\Auth\SendPasswordResetLinkController;
use App\Http\Controllers\Api\Auth\ShowConvoLabBrowserCurrentUserController;
use App\Http\Controllers\Api\Auth\ShowConvoLabCurrentUserController;
use App\Http\Controllers\Api\Auth\ShowCurrentUserController;
use App\Http\Controllers\Api\Auth\StoreMobileTokenController;
use App\Http\Controllers\Api\Auth\UpdateConvoLabCurrentUserController;
use App\Http\Controllers\Api\Auth\UpdateConvoLabCurrentUserPasswordController;
use App\Http\Controllers\Api\Auth\UpdateCurrentUserPasswordController;
use App\Http\Controllers\Api\Auth\UpdateCurrentUserProfileController;
use App\Http\Controllers\Api\Auth\VerifyConvoLabBrowserEmailController;
use Illuminate\Support\Facades\Route;

return [
    'publicAndBrowser' => static function (): void {
        // Sanctum supports first-party sessions now and bearer tokens for mobile clients later.
        Route::post('/auth/register', RegisterMobileUserController::class)
            ->middleware('throttle:'.AuthEmailRateLimiter::MOBILE_REGISTRATIONS);
        Route::post('/convolab/auth/register', RegisterConvoLabMobileUserController::class)
            ->middleware('throttle:'.AuthEmailRateLimiter::CONVOLAB_SIGNUPS);
        Route::post('/auth/password/forgot', SendPasswordResetLinkController::class)
            ->middleware('throttle:'.AuthEmailRateLimiter::PASSWORD_RESET_LINKS);
        Route::post('/auth/password/reset', ResetUserPasswordController::class)
            ->middleware('throttle:'.AuthEmailRateLimiter::PASSWORD_RESET_TOKENS);
        Route::post('/auth/tokens', StoreMobileTokenController::class)
            ->middleware('throttle:'.AuthEmailRateLimiter::MOBILE_TOKENS);

        Route::prefix('/convolab/browser/auth')->group(function (): void {
            Route::post('/login', AuthenticateConvoLabBrowserUserController::class)
                ->middleware('throttle:'.AuthEmailRateLimiter::CONVOLAB_LOGINS);
            Route::post('/signup', RegisterConvoLabBrowserUserController::class)
                ->middleware('throttle:'.AuthEmailRateLimiter::CONVOLAB_SIGNUPS);
            Route::post('/verification', VerifyConvoLabBrowserEmailController::class)
                ->middleware('throttle:'.ConvoLabVerificationRateLimiter::VERIFY);
            Route::post('/google/invite', ClaimConvoLabBrowserGoogleInviteController::class)
                ->middleware('throttle:'.ConvoLabOAuthRateLimiter::BROWSER_CLAIM);

            Route::middleware('auth:web')->group(function (): void {
                Route::get('/me', ShowConvoLabBrowserCurrentUserController::class);
                Route::post('/logout', DestroyConvoLabBrowserSessionController::class);
                Route::post(
                    '/verification/send',
                    SendConvoLabBrowserVerificationController::class,
                )->middleware('throttle:'.ConvoLabVerificationRateLimiter::SEND);
            });
        });
    },
    'authenticatedConvoLab' => static function (): void {
        Route::get('/me', ShowCurrentUserController::class);
        Route::delete('/convolab/auth/google', DisconnectConvoLabGoogleIdentityController::class)
            ->middleware('throttle:'.ConvoLabOAuthRateLimiter::DISCONNECT);
        Route::get('/convolab/auth/me', ShowConvoLabCurrentUserController::class);
        Route::patch('/convolab/auth/me', UpdateConvoLabCurrentUserController::class)
            ->middleware('throttle:'.ConvoLabProfileRateLimiter::NAME);
        Route::put('/convolab/auth/me/password', UpdateConvoLabCurrentUserPasswordController::class)
            ->middleware('throttle:'.ConvoLabAccountSecurityRateLimiter::PASSWORD_UPDATE);
        Route::delete('/convolab/auth/me', DeleteConvoLabCurrentUserController::class)
            ->middleware('throttle:'.ConvoLabAccountSecurityRateLimiter::ACCOUNT_DELETE);
        Route::post('/convolab/auth/verification/send', SendConvoLabVerificationController::class)
            ->middleware('throttle:'.ConvoLabVerificationRateLimiter::SEND);
    },
    'authenticatedAccountAndTokens' => static function (): void {
        Route::put('/me', UpdateCurrentUserProfileController::class)
            ->middleware('throttle:'.AuthAccountRateLimiter::PROFILE_UPDATE);
        Route::put('/me/password', UpdateCurrentUserPasswordController::class)
            ->middleware('throttle:'.AuthAccountRateLimiter::PASSWORD_UPDATE);
        Route::delete('/me', DeleteCurrentUserController::class)
            ->middleware('throttle:'.AuthAccountRateLimiter::ACCOUNT_DELETE);
        Route::get('/auth/tokens', ListAccessTokensController::class);
        // Current and by-id token revokes share one 30/min manual-cleanup bucket, separate from profile/password retries.
        Route::delete('/auth/tokens/current', DestroyCurrentAccessTokenController::class)
            ->middleware('throttle:'.AuthAccountRateLimiter::TOKEN_REVOKE);
        Route::delete('/auth/tokens/{tokenId}', DestroyAccessTokenController::class)
            ->whereNumber('tokenId')
            ->middleware('throttle:'.AuthAccountRateLimiter::TOKEN_REVOKE);
    },
];
