<?php

use App\Domain\Study\Support\StudyCompatibilityTrafficRateLimiter;
use App\Domain\Study\Support\StudySessionStartRateLimiter;
use App\Http\Controllers\Api\Study\BuildStudyOfflineReserveController;
use App\Http\Controllers\Api\Study\StartStudyLessonController;
use App\Http\Controllers\Api\Study\StartStudySessionController;
use Illuminate\Support\Facades\Route;

/** @var callable(): void $publicMediaAnalyticsRoutes */
$publicMediaAnalyticsRoutes = require __DIR__.'/api/public-media-analytics.php';
$publicMediaAnalyticsRoutes();

/**
 * @var array{
 *     publicAndBrowser: callable(): void,
 *     authenticatedConvoLab: callable(): void,
 *     authenticatedAccountAndTokens: callable(): void,
 * } $authRoutes
 */
$authRoutes = require __DIR__.'/api/auth.php';
$authRoutes['publicAndBrowser']();

/** @var callable(): void $adminRoutes */
$adminRoutes = require __DIR__.'/api/admin.php';

/** @var callable(): void $contentRoutes */
$contentRoutes = require __DIR__.'/api/content.php';

/** @var callable(): void $cardRoutes */
$cardRoutes = require __DIR__.'/api/cards.php';

/** @var callable(): void $courseRoutes */
$courseRoutes = require __DIR__.'/api/courses.php';

/** @var callable(): void $deckRoutes */
$deckRoutes = require __DIR__.'/api/decks.php';

/** @var callable(): void $featureFlagRoutes */
$featureFlagRoutes = require __DIR__.'/api/feature-flags.php';

/** @var callable(): void $mediaAssetRoutes */
$mediaAssetRoutes = require __DIR__.'/api/media-assets.php';

/** @var callable(): void $reviewRoutes */
$reviewRoutes = require __DIR__.'/api/reviews.php';

/** @var callable(): void $syncFeedRoutes */
$syncFeedRoutes = require __DIR__.'/api/sync-feed.php';

/** @var callable(): void $studyActivityRoutes */
$studyActivityRoutes = require __DIR__.'/api/study-activity.php';

/** @var callable(): void $studyBrowserRoutes */
$studyBrowserRoutes = require __DIR__.'/api/study-browser.php';

/** @var callable(): void $studyCardAuthoringRoutes */
$studyCardAuthoringRoutes = require __DIR__.'/api/study-card-authoring.php';

/** @var callable(): void $studyCardLibraryRoutes */
$studyCardLibraryRoutes = require __DIR__.'/api/study-card-library.php';

/** @var callable(): void $studyCardMutationRoutes */
$studyCardMutationRoutes = require __DIR__.'/api/study-card-mutations.php';

/** @var callable(): void $studyDailyAudioRoutes */
$studyDailyAudioRoutes = require __DIR__.'/api/study-daily-audio.php';

/** @var callable(): void $studyExportRoutes */
$studyExportRoutes = require __DIR__.'/api/study-exports.php';

/** @var callable(): void $studyImportRoutes */
$studyImportRoutes = require __DIR__.'/api/study-imports.php';

/** @var callable(): void $studyMediaRoutes */
$studyMediaRoutes = require __DIR__.'/api/study-media.php';

/** @var callable(): void $studyPreferenceJapaneseRoutes */
$studyPreferenceJapaneseRoutes = require __DIR__.'/api/study-preferences-japanese.php';

/** @var callable(): void $studyReviewRoutes */
$studyReviewRoutes = require __DIR__.'/api/study-reviews.php';

Route::middleware('auth:sanctum')->group(function () use ($adminRoutes, $authRoutes, $cardRoutes, $contentRoutes, $courseRoutes, $deckRoutes, $featureFlagRoutes, $mediaAssetRoutes, $reviewRoutes, $studyActivityRoutes, $studyBrowserRoutes, $studyCardAuthoringRoutes, $studyCardLibraryRoutes, $studyCardMutationRoutes, $studyDailyAudioRoutes, $studyExportRoutes, $studyImportRoutes, $studyMediaRoutes, $studyPreferenceJapaneseRoutes, $studyReviewRoutes, $syncFeedRoutes): void {
    $authRoutes['authenticatedConvoLab']();
    $adminRoutes();
    $contentRoutes();
    $featureFlagRoutes();
    $authRoutes['authenticatedAccountAndTokens']();
    $courseRoutes();
    $reviewRoutes();
    $cardRoutes();
    $mediaAssetRoutes();
    $syncFeedRoutes();
    // Preserve the retired ConvoLab proxy ceilings: every request consumes the shared
    // network bucket, while reads consume an additional actor-scoped read or media bucket.
    Route::middleware('throttle:'.StudyCompatibilityTrafficRateLimiter::NETWORK_NAME)
        ->group(function () use ($studyActivityRoutes, $studyBrowserRoutes, $studyCardAuthoringRoutes, $studyCardLibraryRoutes, $studyCardMutationRoutes, $studyDailyAudioRoutes, $studyExportRoutes, $studyImportRoutes, $studyMediaRoutes, $studyPreferenceJapaneseRoutes, $studyReviewRoutes): void {
            Route::post('/study/session/start', StartStudySessionController::class)
                ->middleware('throttle:'.StudySessionStartRateLimiter::NAME);
            Route::post('/study/lessons/start', StartStudyLessonController::class)
                ->middleware('throttle:'.StudySessionStartRateLimiter::NAME);
            Route::post('/study/offline-reserve', BuildStudyOfflineReserveController::class)
                ->middleware('throttle:'.StudySessionStartRateLimiter::NAME);
            $studyDailyAudioRoutes();
            $studyExportRoutes();
            $studyImportRoutes();
            $studyBrowserRoutes();
            $studyCardAuthoringRoutes();
            $studyCardLibraryRoutes();
            $studyActivityRoutes();
            $studyReviewRoutes();
            $studyCardMutationRoutes();
            $studyMediaRoutes();
            $studyPreferenceJapaneseRoutes();
        });
    $deckRoutes();
});

unset($adminRoutes, $authRoutes, $cardRoutes, $contentRoutes, $courseRoutes, $deckRoutes, $featureFlagRoutes, $mediaAssetRoutes, $publicMediaAnalyticsRoutes, $reviewRoutes, $studyActivityRoutes, $studyBrowserRoutes, $studyCardAuthoringRoutes, $studyCardLibraryRoutes, $studyCardMutationRoutes, $studyDailyAudioRoutes, $studyExportRoutes, $studyImportRoutes, $studyMediaRoutes, $studyPreferenceJapaneseRoutes, $studyReviewRoutes, $syncFeedRoutes);
