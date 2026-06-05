<?php

use App\Http\Controllers\Api\Auth\DestroyAccessTokenController;
use App\Http\Controllers\Api\Auth\DestroyCurrentAccessTokenController;
use App\Http\Controllers\Api\Auth\ListAccessTokensController;
use App\Http\Controllers\Api\Auth\RegisterMobileUserController;
use App\Http\Controllers\Api\Auth\ResetUserPasswordController;
use App\Http\Controllers\Api\Auth\SendPasswordResetLinkController;
use App\Http\Controllers\Api\Auth\ShowCurrentUserController;
use App\Http\Controllers\Api\Auth\StoreMobileTokenController;
use App\Http\Controllers\Api\Auth\UpdateCurrentUserPasswordController;
use App\Http\Controllers\Api\Auth\UpdateCurrentUserProfileController;
use App\Http\Controllers\Api\Courses\DeleteCourseController;
use App\Http\Controllers\Api\Courses\ListCoursesController;
use App\Http\Controllers\Api\Courses\ShowCourseController;
use App\Http\Controllers\Api\Courses\StoreCourseController;
use App\Http\Controllers\Api\Courses\UpdateCourseController;
use App\Http\Controllers\Api\Flashcards\DeleteCardController;
use App\Http\Controllers\Api\Flashcards\DeleteDeckController;
use App\Http\Controllers\Api\Flashcards\ListCardsController;
use App\Http\Controllers\Api\Flashcards\ListDeckCardsController;
use App\Http\Controllers\Api\Flashcards\ListDecksController;
use App\Http\Controllers\Api\Flashcards\ListDueCardsController;
use App\Http\Controllers\Api\Flashcards\ListNewCardsController;
use App\Http\Controllers\Api\Flashcards\PerformCardStudyActionController;
use App\Http\Controllers\Api\Flashcards\ReorderNewCardQueueController;
use App\Http\Controllers\Api\Flashcards\ShowCardController;
use App\Http\Controllers\Api\Flashcards\ShowDeckController;
use App\Http\Controllers\Api\Flashcards\StoreCardController;
use App\Http\Controllers\Api\Flashcards\StoreDeckController;
use App\Http\Controllers\Api\Flashcards\UpdateCardController;
use App\Http\Controllers\Api\Flashcards\UpdateCardStudyStatusController;
use App\Http\Controllers\Api\Flashcards\UpdateDeckController;
use App\Http\Controllers\Api\Media\AttachMediaToCardController;
use App\Http\Controllers\Api\Media\DeleteMediaAssetController;
use App\Http\Controllers\Api\Media\DetachMediaFromCardController;
use App\Http\Controllers\Api\Media\ListCardMediaAssetsController;
use App\Http\Controllers\Api\Media\ListDeckMediaAssetsController;
use App\Http\Controllers\Api\Media\ListMediaAssetsController;
use App\Http\Controllers\Api\Media\ShowMediaAssetController;
use App\Http\Controllers\Api\Media\StoreMediaAssetController;
use App\Http\Controllers\Api\Reviews\ListCardReviewEventsController;
use App\Http\Controllers\Api\Reviews\ListReviewEventsController;
use App\Http\Controllers\Api\Reviews\ShowCardReviewEventController;
use App\Http\Controllers\Api\Reviews\StoreCardReviewEventBatchController;
use App\Http\Controllers\Api\Reviews\StoreCardReviewEventController;
use App\Http\Controllers\Api\Reviews\UndoCardReviewEventController;
use App\Http\Controllers\Api\Study\ListStudyExportCardsController;
use App\Http\Controllers\Api\Study\ListStudyExportCoursesController;
use App\Http\Controllers\Api\Study\ListStudyExportDecksController;
use App\Http\Controllers\Api\Study\ListStudyExportMediaAssetsController;
use App\Http\Controllers\Api\Study\ListStudyExportReviewEventsController;
use App\Http\Controllers\Api\Study\ShowStudyExportManifestController;
use App\Http\Controllers\Api\Study\ShowStudyOverviewController;
use App\Http\Controllers\Api\Study\ShowStudySettingsController;
use App\Http\Controllers\Api\Study\StartStudySessionController;
use App\Http\Controllers\Api\Study\UpdateStudySettingsController;
use App\Http\Controllers\Api\Sync\ListSyncFeedEntriesController;
use Illuminate\Support\Facades\Route;

// Sanctum supports first-party sessions now and bearer tokens for mobile clients later.
Route::post('/auth/register', RegisterMobileUserController::class)
    ->middleware('throttle:mobile-registrations');
Route::post('/auth/password/forgot', SendPasswordResetLinkController::class)
    ->middleware('throttle:password-reset-links');
Route::post('/auth/password/reset', ResetUserPasswordController::class)
    ->middleware('throttle:password-reset-tokens');
Route::post('/auth/tokens', StoreMobileTokenController::class)
    ->middleware('throttle:mobile-tokens');

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('/me', ShowCurrentUserController::class);
    Route::put('/me', UpdateCurrentUserProfileController::class);
    Route::put('/me/password', UpdateCurrentUserPasswordController::class);
    Route::get('/auth/tokens', ListAccessTokensController::class);
    Route::delete('/auth/tokens/current', DestroyCurrentAccessTokenController::class);
    Route::delete('/auth/tokens/{tokenId}', DestroyAccessTokenController::class)->whereNumber('tokenId');
    Route::get('/courses', ListCoursesController::class);
    Route::post('/courses', StoreCourseController::class);
    Route::get('/courses/{course}', ShowCourseController::class)->whereUlid('course');
    Route::put('/courses/{course}', UpdateCourseController::class)->whereUlid('course');
    Route::delete('/courses/{course}', DeleteCourseController::class)->whereUlid('course');
    Route::get('/card-review-events', ListReviewEventsController::class);
    Route::post('/card-review-events/batch', StoreCardReviewEventBatchController::class);
    Route::get('/card-review-events/{cardReviewEvent}', ShowCardReviewEventController::class)->whereUlid('cardReviewEvent');
    // Review undo hard-deletes the event; DELETE retries for already-undone events resolve as 404.
    Route::delete('/card-review-events/{cardReviewEvent}', UndoCardReviewEventController::class)->whereUlid('cardReviewEvent');
    Route::post('/card-review-events', StoreCardReviewEventController::class);
    Route::get('/cards/due', ListDueCardsController::class);
    Route::get('/cards/new', ListNewCardsController::class);
    Route::post('/cards/new/reorder', ReorderNewCardQueueController::class);
    Route::get('/cards/{card}', ShowCardController::class)->whereUlid('card');
    Route::get('/cards/{card}/review-events', ListCardReviewEventsController::class)->whereUlid('card');
    Route::get('/cards/{card}/media-assets', ListCardMediaAssetsController::class)->whereUlid('card');
    Route::post('/cards/{card}/media-assets', AttachMediaToCardController::class)->whereUlid('card');
    Route::delete('/cards/{card}/media-assets/{mediaAsset}', DetachMediaFromCardController::class)
        ->whereUlid('card')
        ->whereUlid('mediaAsset');
    Route::get('/cards', ListCardsController::class);
    Route::post('/cards', StoreCardController::class);
    Route::post('/cards/{card}/actions', PerformCardStudyActionController::class)->whereUlid('card');
    Route::patch('/cards/{card}/study-status', UpdateCardStudyStatusController::class)->whereUlid('card');
    Route::put('/cards/{card}', UpdateCardController::class)->whereUlid('card');
    Route::delete('/cards/{card}', DeleteCardController::class)->whereUlid('card');
    Route::get('/media-assets', ListMediaAssetsController::class);
    Route::post('/media-assets', StoreMediaAssetController::class);
    Route::get('/media-assets/{mediaAsset}', ShowMediaAssetController::class);
    // Use a raw ID segment so missing/cross-user media assets stay idempotent 204s.
    Route::delete('/media-assets/{mediaAssetId}', DeleteMediaAssetController::class);
    Route::get('/sync/feed', ListSyncFeedEntriesController::class);
    Route::post('/study/session/start', StartStudySessionController::class);
    Route::get('/study/export', ShowStudyExportManifestController::class);
    Route::get('/study/export/cards', ListStudyExportCardsController::class)->name('api.study.export.cards');
    Route::get('/study/export/courses', ListStudyExportCoursesController::class)->name('api.study.export.courses');
    Route::get('/study/export/decks', ListStudyExportDecksController::class)->name('api.study.export.decks');
    Route::get('/study/export/media-assets', ListStudyExportMediaAssetsController::class)->name('api.study.export.media-assets');
    Route::get('/study/export/review-events', ListStudyExportReviewEventsController::class)->name('api.study.export.review-events');
    Route::get('/study/overview', ShowStudyOverviewController::class);
    Route::get('/study/settings', ShowStudySettingsController::class);
    Route::patch('/study/settings', UpdateStudySettingsController::class);
    Route::prefix('/decks/{deck}')
        ->whereUlid('deck')
        ->group(function (): void {
            Route::get('/', ShowDeckController::class);
            Route::get('/media-assets', ListDeckMediaAssetsController::class);
            Route::get('/cards', ListDeckCardsController::class);
            Route::put('/', UpdateDeckController::class);
            Route::delete('/', DeleteDeckController::class);
        });
    Route::get('/decks', ListDecksController::class);
    Route::post('/decks', StoreDeckController::class);
});
