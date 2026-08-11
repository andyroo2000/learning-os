<?php

use App\Domain\Flashcards\Models\Card;
use App\Domain\Japanese\Support\JapaneseKnowledgeRateLimiter;
use App\Domain\Study\Support\StudyCardActionRateLimiter;
use App\Domain\Study\Support\StudyCardCreateRateLimiter;
use App\Domain\Study\Support\StudyCardDeleteRateLimiter;
use App\Domain\Study\Support\StudyCardUpdateRateLimiter;
use App\Domain\Study\Support\StudyCompatibilityTrafficRateLimiter;
use App\Domain\Study\Support\StudySessionStartRateLimiter;
use App\Domain\Study\Support\StudySettingsUpdateRateLimiter;
use App\Http\Controllers\Api\Media\DownloadMediaAssetContentController;
use App\Http\Controllers\Api\Study\BuildStudyOfflineReserveController;
use App\Http\Controllers\Api\Study\ConnectWaniKaniController;
use App\Http\Controllers\Api\Study\DeleteStudyCardController;
use App\Http\Controllers\Api\Study\DisconnectWaniKaniController;
use App\Http\Controllers\Api\Study\DownloadStudyMediaBatchController;
use App\Http\Controllers\Api\Study\PerformStudyCardActionController;
use App\Http\Controllers\Api\Study\SetManualKnownKanjiController;
use App\Http\Controllers\Api\Study\ShowKnownKanjiController;
use App\Http\Controllers\Api\Study\ShowStudySettingsController;
use App\Http\Controllers\Api\Study\StartStudyLessonController;
use App\Http\Controllers\Api\Study\StartStudySessionController;
use App\Http\Controllers\Api\Study\StoreStudyCardController;
use App\Http\Controllers\Api\Study\SyncWaniKaniKanjiController;
use App\Http\Controllers\Api\Study\UpdateStudyCardController;
use App\Http\Controllers\Api\Study\UpdateStudySettingsController;
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

/** @var callable(): void $studyDailyAudioRoutes */
$studyDailyAudioRoutes = require __DIR__.'/api/study-daily-audio.php';

/** @var callable(): void $studyExportRoutes */
$studyExportRoutes = require __DIR__.'/api/study-exports.php';

/** @var callable(): void $studyImportRoutes */
$studyImportRoutes = require __DIR__.'/api/study-imports.php';

/** @var callable(): void $studyReviewRoutes */
$studyReviewRoutes = require __DIR__.'/api/study-reviews.php';

Route::middleware('auth:sanctum')->group(function () use ($adminRoutes, $authRoutes, $cardRoutes, $contentRoutes, $courseRoutes, $deckRoutes, $featureFlagRoutes, $mediaAssetRoutes, $reviewRoutes, $studyActivityRoutes, $studyBrowserRoutes, $studyCardAuthoringRoutes, $studyCardLibraryRoutes, $studyDailyAudioRoutes, $studyExportRoutes, $studyImportRoutes, $studyReviewRoutes, $syncFeedRoutes): void {
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
        ->group(function () use ($studyActivityRoutes, $studyBrowserRoutes, $studyCardAuthoringRoutes, $studyCardLibraryRoutes, $studyDailyAudioRoutes, $studyExportRoutes, $studyImportRoutes, $studyReviewRoutes): void {
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
            // Shares the study-card creation rate limit with draft creation and commits.
            Route::post('/study/cards', StoreStudyCardController::class)
                ->middleware('throttle:'.StudyCardCreateRateLimiter::NAME);
            Route::delete('/study/cards/{cardId}', DeleteStudyCardController::class)
                ->where('cardId', Card::CLIENT_ID_ROUTE_PATTERN)
                ->middleware('throttle:'.StudyCardDeleteRateLimiter::NAME);
            // Manual card actions can be retried independently from create/update/delete writes.
            Route::post('/study/cards/{cardId}/actions', PerformStudyCardActionController::class)
                ->where('cardId', Card::CLIENT_ID_ROUTE_PATTERN)
                ->middleware('throttle:'.StudyCardActionRateLimiter::NAME);
            // Saved-card edits can be retried by sync clients; keep their rate limit separate.
            Route::patch('/study/cards/{cardId}', UpdateStudyCardController::class)
                ->where('cardId', Card::CLIENT_ID_ROUTE_PATTERN)
                ->middleware('throttle:'.StudyCardUpdateRateLimiter::NAME);
            Route::post('/study/media/batch', DownloadStudyMediaBatchController::class)
                ->middleware('throttle:'.StudyCompatibilityTrafficRateLimiter::MEDIA_NAME);
            Route::get('/study/media/{mediaAsset}', DownloadMediaAssetContentController::class)
                ->whereUlid('mediaAsset')
                ->middleware('throttle:'.StudyCompatibilityTrafficRateLimiter::MEDIA_NAME);
            Route::get('/study/settings', ShowStudySettingsController::class)
                ->middleware('throttle:'.StudyCompatibilityTrafficRateLimiter::READ_NAME);
            // Settings sync can retry updates; keep that rate limit separate from card writes.
            Route::patch('/study/settings', UpdateStudySettingsController::class)
                ->middleware('throttle:'.StudySettingsUpdateRateLimiter::NAME);
            Route::get('/study/known-kanji', ShowKnownKanjiController::class)
                ->middleware('throttle:'.StudyCompatibilityTrafficRateLimiter::READ_NAME);
            Route::patch('/study/known-kanji/manual', SetManualKnownKanjiController::class)
                ->middleware('throttle:'.JapaneseKnowledgeRateLimiter::MANUAL_NAME);
            Route::put('/study/wanikani', ConnectWaniKaniController::class)
                ->middleware('throttle:'.JapaneseKnowledgeRateLimiter::CONNECTION_NAME);
            Route::delete('/study/wanikani', DisconnectWaniKaniController::class)
                ->middleware('throttle:'.JapaneseKnowledgeRateLimiter::CONNECTION_NAME);
            Route::post('/study/wanikani/sync', SyncWaniKaniKanjiController::class)
                ->middleware('throttle:'.JapaneseKnowledgeRateLimiter::SYNC_NAME);
        });
    $deckRoutes();
});

unset($adminRoutes, $authRoutes, $cardRoutes, $contentRoutes, $courseRoutes, $deckRoutes, $featureFlagRoutes, $mediaAssetRoutes, $publicMediaAnalyticsRoutes, $reviewRoutes, $studyActivityRoutes, $studyBrowserRoutes, $studyCardAuthoringRoutes, $studyCardLibraryRoutes, $studyDailyAudioRoutes, $studyExportRoutes, $studyImportRoutes, $studyReviewRoutes, $syncFeedRoutes);
