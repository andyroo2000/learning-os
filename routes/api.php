<?php

use App\Domain\Auth\Support\AuthEmailRateLimiter;
use App\Domain\Study\Support\StudyCardCreateRateLimiter;
use App\Domain\Study\Support\StudyCardDeleteRateLimiter;
use App\Domain\Study\Support\StudyCardDraftAutosaveRateLimiter;
use App\Domain\Study\Support\StudyCardDraftDeleteRateLimiter;
use App\Domain\Study\Support\StudyCardUpdateRateLimiter;
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
use App\Http\Controllers\Api\Media\DownloadMediaAssetContentController;
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
use App\Http\Controllers\Api\Study\CancelStudyImportUploadController;
use App\Http\Controllers\Api\Study\CompleteStudyImportUploadController;
use App\Http\Controllers\Api\Study\DeleteStudyCardController;
use App\Http\Controllers\Api\Study\DeleteStudyCardDraftController;
use App\Http\Controllers\Api\Study\ListStudyBrowserController;
use App\Http\Controllers\Api\Study\ListStudyCardDraftsController;
use App\Http\Controllers\Api\Study\ListStudyExportCardMediaController;
use App\Http\Controllers\Api\Study\ListStudyExportCardsController;
use App\Http\Controllers\Api\Study\ListStudyExportCoursesController;
use App\Http\Controllers\Api\Study\ListStudyExportDecksController;
use App\Http\Controllers\Api\Study\ListStudyExportImportJobsController;
use App\Http\Controllers\Api\Study\ListStudyExportMediaAssetsController;
use App\Http\Controllers\Api\Study\ListStudyExportReviewEventsController;
use App\Http\Controllers\Api\Study\ListStudyImportJobsController;
use App\Http\Controllers\Api\Study\ListStudyNewCardQueueController;
use App\Http\Controllers\Api\Study\PerformStudyCardActionController;
use App\Http\Controllers\Api\Study\ReorderStudyNewCardQueueController;
use App\Http\Controllers\Api\Study\ShowCurrentStudyImportJobController;
use App\Http\Controllers\Api\Study\ShowStudyBrowserNoteController;
use App\Http\Controllers\Api\Study\ShowStudyCardDraftController;
use App\Http\Controllers\Api\Study\ShowStudyExportManifestController;
use App\Http\Controllers\Api\Study\ShowStudyExportSettingsController;
use App\Http\Controllers\Api\Study\ShowStudyImportJobController;
use App\Http\Controllers\Api\Study\ShowStudyImportReadinessController;
use App\Http\Controllers\Api\Study\ShowStudyOverviewController;
use App\Http\Controllers\Api\Study\ShowStudySettingsController;
use App\Http\Controllers\Api\Study\StartStudySessionController;
use App\Http\Controllers\Api\Study\StoreStudyCardController;
use App\Http\Controllers\Api\Study\StoreStudyCardDraftController;
use App\Http\Controllers\Api\Study\StoreStudyCardFromDraftController;
use App\Http\Controllers\Api\Study\StoreStudyImportController;
use App\Http\Controllers\Api\Study\StoreStudyReviewController;
use App\Http\Controllers\Api\Study\StoreStudyReviewUndoController;
use App\Http\Controllers\Api\Study\UndoStudyReviewController;
use App\Http\Controllers\Api\Study\UpdateStudyCardController;
use App\Http\Controllers\Api\Study\UpdateStudyCardDraftController;
use App\Http\Controllers\Api\Study\UpdateStudySettingsController;
use App\Http\Controllers\Api\Study\UploadStudyImportFileController;
use App\Http\Controllers\Api\Sync\ListSyncFeedEntriesController;
use Illuminate\Support\Facades\Route;

// Sanctum supports first-party sessions now and bearer tokens for mobile clients later.
Route::post('/auth/register', RegisterMobileUserController::class)
    ->middleware('throttle:'.AuthEmailRateLimiter::MOBILE_REGISTRATIONS);
Route::post('/auth/password/forgot', SendPasswordResetLinkController::class)
    ->middleware('throttle:'.AuthEmailRateLimiter::PASSWORD_RESET_LINKS);
Route::post('/auth/password/reset', ResetUserPasswordController::class)
    ->middleware('throttle:'.AuthEmailRateLimiter::PASSWORD_RESET_TOKENS);
Route::post('/auth/tokens', StoreMobileTokenController::class)
    ->middleware('throttle:'.AuthEmailRateLimiter::MOBILE_TOKENS);

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
    Route::get('/media-assets/{mediaAsset}/content', DownloadMediaAssetContentController::class)
        ->name('api.media-assets.content');
    Route::get('/media-assets/{mediaAsset}', ShowMediaAssetController::class);
    // Use a raw ID segment so missing/cross-user media assets stay idempotent 204s.
    Route::delete('/media-assets/{mediaAssetId}', DeleteMediaAssetController::class);
    Route::get('/sync/feed', ListSyncFeedEntriesController::class);
    Route::post('/study/session/start', StartStudySessionController::class);
    Route::get('/study/export', ShowStudyExportManifestController::class);
    Route::get('/study/export/card-media', ListStudyExportCardMediaController::class)->name('api.study.export.card-media');
    Route::get('/study/export/cards', ListStudyExportCardsController::class)->name('api.study.export.cards');
    Route::get('/study/export/courses', ListStudyExportCoursesController::class)->name('api.study.export.courses');
    Route::get('/study/export/decks', ListStudyExportDecksController::class)->name('api.study.export.decks');
    Route::get('/study/export/imports', ListStudyExportImportJobsController::class)->name('api.study.export.imports');
    Route::get('/study/export/media', ListStudyExportMediaAssetsController::class)->name('api.study.export.media');
    Route::get('/study/export/media-assets', ListStudyExportMediaAssetsController::class)->name('api.study.export.media-assets');
    Route::get('/study/export/review-logs', ListStudyExportReviewEventsController::class)->name('api.study.export.review-logs');
    Route::get('/study/export/review-events', ListStudyExportReviewEventsController::class)->name('api.study.export.review-events');
    Route::get('/study/export/settings', ShowStudyExportSettingsController::class)->name('api.study.export.settings');
    Route::get('/study/imports', ListStudyImportJobsController::class);
    Route::post('/study/imports', StoreStudyImportController::class);
    Route::get('/study/imports/readiness', ShowStudyImportReadinessController::class);
    Route::get('/study/imports/current', ShowCurrentStudyImportJobController::class);
    Route::put('/study/imports/{studyImportJobId}/upload', UploadStudyImportFileController::class)
        ->whereUlid('studyImportJobId')
        ->name('api.study.imports.upload');
    Route::post('/study/imports/{studyImportJobId}/complete', CompleteStudyImportUploadController::class)
        ->whereUlid('studyImportJobId');
    Route::post('/study/imports/{studyImportJobId}/cancel', CancelStudyImportUploadController::class)
        ->whereUlid('studyImportJobId');
    Route::get('/study/imports/{studyImportJobId}', ShowStudyImportJobController::class)->whereUlid('studyImportJobId');
    Route::get('/study/browser', ListStudyBrowserController::class);
    // Supports numeric imported note IDs and Laravel ULID card IDs; neither format uses separators.
    Route::get('/study/browser/{noteId}', ShowStudyBrowserNoteController::class)->where('noteId', '[A-Za-z0-9]+');
    Route::get('/study/card-drafts', ListStudyCardDraftsController::class);
    Route::get('/study/card-drafts/{draftId}', ShowStudyCardDraftController::class)->whereUlid('draftId');
    // Draft creation, draft commits, and final manual-card creation share one user-scoped creation quota.
    Route::post('/study/card-drafts/{draftId}/card', StoreStudyCardFromDraftController::class)
        ->whereUlid('draftId')
        ->middleware('throttle:'.StudyCardCreateRateLimiter::NAME);
    Route::post('/study/card-drafts', StoreStudyCardDraftController::class)
        ->middleware('throttle:'.StudyCardCreateRateLimiter::NAME);
    Route::patch('/study/card-drafts/{draftId}', UpdateStudyCardDraftController::class)
        ->whereUlid('draftId')
        ->middleware('throttle:'.StudyCardDraftAutosaveRateLimiter::NAME);
    Route::delete('/study/card-drafts/{draftId}', DeleteStudyCardDraftController::class)
        ->whereUlid('draftId')
        ->middleware('throttle:'.StudyCardDraftDeleteRateLimiter::NAME);
    Route::get('/study/new-queue', ListStudyNewCardQueueController::class);
    Route::post('/study/new-queue/reorder', ReorderStudyNewCardQueueController::class);
    Route::get('/study/overview', ShowStudyOverviewController::class);
    Route::post('/study/reviews', StoreStudyReviewController::class);
    Route::post('/study/reviews/undo', StoreStudyReviewUndoController::class);
    Route::delete('/study/reviews/{reviewLogId}', UndoStudyReviewController::class)->whereUlid('reviewLogId');
    // Shares the study-card creation quota with draft creation and draft commits.
    Route::post('/study/cards', StoreStudyCardController::class)
        ->middleware('throttle:'.StudyCardCreateRateLimiter::NAME);
    Route::delete('/study/cards/{cardId}', DeleteStudyCardController::class)
        ->whereUlid('cardId')
        ->middleware('throttle:'.StudyCardDeleteRateLimiter::NAME);
    Route::post('/study/cards/{cardId}/actions', PerformStudyCardActionController::class)->whereUlid('cardId');
    // Saved-card edits can be retried by sync clients; keep their quota separate from creation.
    Route::patch('/study/cards/{cardId}', UpdateStudyCardController::class)
        ->whereUlid('cardId')
        ->middleware('throttle:'.StudyCardUpdateRateLimiter::NAME);
    Route::get('/study/media/{mediaAsset}', DownloadMediaAssetContentController::class)->whereUlid('mediaAsset');
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
