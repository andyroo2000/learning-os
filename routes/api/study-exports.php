<?php

use App\Domain\Study\Support\StudyCompatibilityTrafficRateLimiter;
use App\Http\Controllers\Api\Study\ListStudyExportCardDraftsController;
use App\Http\Controllers\Api\Study\ListStudyExportCardMediaController;
use App\Http\Controllers\Api\Study\ListStudyExportCardsController;
use App\Http\Controllers\Api\Study\ListStudyExportCoursesController;
use App\Http\Controllers\Api\Study\ListStudyExportDecksController;
use App\Http\Controllers\Api\Study\ListStudyExportImportJobsController;
use App\Http\Controllers\Api\Study\ListStudyExportMediaAssetsController;
use App\Http\Controllers\Api\Study\ListStudyExportReviewEventsController;
use App\Http\Controllers\Api\Study\ShowStudyExportManifestController;
use App\Http\Controllers\Api\Study\ShowStudyExportSettingsController;
use Illuminate\Support\Facades\Route;

return static function (): void {
    Route::get('/study/export', ShowStudyExportManifestController::class)
        ->middleware('throttle:'.StudyCompatibilityTrafficRateLimiter::READ_NAME);
    Route::get('/study/export/card-drafts', ListStudyExportCardDraftsController::class)
        ->name('api.study.export.card-drafts')
        ->middleware('throttle:'.StudyCompatibilityTrafficRateLimiter::READ_NAME);
    Route::get('/study/export/card-media', ListStudyExportCardMediaController::class)
        ->name('api.study.export.card-media')
        ->middleware('throttle:'.StudyCompatibilityTrafficRateLimiter::READ_NAME);
    Route::get('/study/export/cards', ListStudyExportCardsController::class)
        ->name('api.study.export.cards')
        ->middleware('throttle:'.StudyCompatibilityTrafficRateLimiter::READ_NAME);
    Route::get('/study/export/courses', ListStudyExportCoursesController::class)
        ->name('api.study.export.courses')
        ->middleware('throttle:'.StudyCompatibilityTrafficRateLimiter::READ_NAME);
    Route::get('/study/export/decks', ListStudyExportDecksController::class)
        ->name('api.study.export.decks')
        ->middleware('throttle:'.StudyCompatibilityTrafficRateLimiter::READ_NAME);
    Route::get('/study/export/imports', ListStudyExportImportJobsController::class)
        ->name('api.study.export.imports')
        ->middleware('throttle:'.StudyCompatibilityTrafficRateLimiter::READ_NAME);
    Route::get('/study/export/media', ListStudyExportMediaAssetsController::class)
        ->name('api.study.export.media')
        ->middleware('throttle:'.StudyCompatibilityTrafficRateLimiter::READ_NAME);
    Route::get('/study/export/media-assets', ListStudyExportMediaAssetsController::class)
        ->name('api.study.export.media-assets')
        ->middleware('throttle:'.StudyCompatibilityTrafficRateLimiter::READ_NAME);
    Route::get('/study/export/review-logs', ListStudyExportReviewEventsController::class)
        ->name('api.study.export.review-logs')
        ->middleware('throttle:'.StudyCompatibilityTrafficRateLimiter::READ_NAME);
    Route::get('/study/export/review-events', ListStudyExportReviewEventsController::class)
        ->name('api.study.export.review-events')
        ->middleware('throttle:'.StudyCompatibilityTrafficRateLimiter::READ_NAME);
    Route::get('/study/export/settings', ShowStudyExportSettingsController::class)
        ->name('api.study.export.settings')
        ->middleware('throttle:'.StudyCompatibilityTrafficRateLimiter::READ_NAME);
};
