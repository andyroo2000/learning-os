<?php

use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Support\NewCardQueueReorderRateLimiter;
use App\Domain\Japanese\Support\JapaneseKnowledgeRateLimiter;
use App\Domain\Reviews\Support\CardReviewEventCreateRateLimiter;
use App\Domain\Reviews\Support\CardReviewEventUndoRateLimiter;
use App\Domain\Study\Support\StudyActivitySessionRateLimiter;
use App\Domain\Study\Support\StudyCardActionRateLimiter;
use App\Domain\Study\Support\StudyCardAudioPrepareRateLimiter;
use App\Domain\Study\Support\StudyCardCreateRateLimiter;
use App\Domain\Study\Support\StudyCardDeleteRateLimiter;
use App\Domain\Study\Support\StudyCardDraftAutosaveRateLimiter;
use App\Domain\Study\Support\StudyCardDraftDeleteRateLimiter;
use App\Domain\Study\Support\StudyCardDraftRetryRateLimiter;
use App\Domain\Study\Support\StudyCardPitchAccentRateLimiter;
use App\Domain\Study\Support\StudyCardUpdateRateLimiter;
use App\Domain\Study\Support\StudyCompatibilityTrafficRateLimiter;
use App\Domain\Study\Support\StudySessionStartRateLimiter;
use App\Domain\Study\Support\StudySettingsUpdateRateLimiter;
use App\Domain\Study\Support\StudyVocabBundleDraftRateLimiter;
use App\Http\Controllers\Api\Media\DownloadMediaAssetContentController;
use App\Http\Controllers\Api\Study\BuildStudyOfflineReserveController;
use App\Http\Controllers\Api\Study\ConnectWaniKaniController;
use App\Http\Controllers\Api\Study\DeleteStudyActivitySessionController;
use App\Http\Controllers\Api\Study\DeleteStudyCardController;
use App\Http\Controllers\Api\Study\DeleteStudyCardDraftController;
use App\Http\Controllers\Api\Study\DisconnectWaniKaniController;
use App\Http\Controllers\Api\Study\DownloadStudyMediaBatchController;
use App\Http\Controllers\Api\Study\GenerateStudyCardDraftPreviewAudioController;
use App\Http\Controllers\Api\Study\GenerateStudyCardDraftPreviewImageController;
use App\Http\Controllers\Api\Study\ListStudyActivitySessionsController;
use App\Http\Controllers\Api\Study\ListStudyCardBatchController;
use App\Http\Controllers\Api\Study\ListStudyCardDraftsController;
use App\Http\Controllers\Api\Study\ListStudyCardsController;
use App\Http\Controllers\Api\Study\ListStudyNewCardQueueController;
use App\Http\Controllers\Api\Study\PerformStudyCardActionController;
use App\Http\Controllers\Api\Study\PrepareStudyCardAnswerAudioController;
use App\Http\Controllers\Api\Study\RegenerateStudyCardAnswerAudioController;
use App\Http\Controllers\Api\Study\RegenerateStudyCardImageController;
use App\Http\Controllers\Api\Study\ReorderStudyNewCardQueueController;
use App\Http\Controllers\Api\Study\ResolveStudyCardPitchAccentController;
use App\Http\Controllers\Api\Study\RetryStudyCardDraftController;
use App\Http\Controllers\Api\Study\SetManualKnownKanjiController;
use App\Http\Controllers\Api\Study\ShowKnownKanjiController;
use App\Http\Controllers\Api\Study\ShowStudyActivityAnalyticsController;
use App\Http\Controllers\Api\Study\ShowStudyCardController;
use App\Http\Controllers\Api\Study\ShowStudyCardDraftController;
use App\Http\Controllers\Api\Study\ShowStudyOverviewController;
use App\Http\Controllers\Api\Study\ShowStudySettingsController;
use App\Http\Controllers\Api\Study\StartStudyLessonController;
use App\Http\Controllers\Api\Study\StartStudySessionController;
use App\Http\Controllers\Api\Study\StoreStudyActivitySessionsController;
use App\Http\Controllers\Api\Study\StoreStudyCardController;
use App\Http\Controllers\Api\Study\StoreStudyCardDraftController;
use App\Http\Controllers\Api\Study\StoreStudyCardFromDraftController;
use App\Http\Controllers\Api\Study\StoreStudyReviewController;
use App\Http\Controllers\Api\Study\StoreStudyReviewUndoController;
use App\Http\Controllers\Api\Study\StoreStudyVocabBundleDraftsController;
use App\Http\Controllers\Api\Study\SyncWaniKaniKanjiController;
use App\Http\Controllers\Api\Study\UndoStudyReviewController;
use App\Http\Controllers\Api\Study\UpdateStudyCardController;
use App\Http\Controllers\Api\Study\UpdateStudyCardDraftController;
use App\Http\Controllers\Api\Study\UpdateStudySettingsController;
use App\Http\Controllers\Api\Study\UploadStudyCardImageController;
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

/** @var callable(): void $studyBrowserRoutes */
$studyBrowserRoutes = require __DIR__.'/api/study-browser.php';

/** @var callable(): void $studyDailyAudioRoutes */
$studyDailyAudioRoutes = require __DIR__.'/api/study-daily-audio.php';

/** @var callable(): void $studyExportRoutes */
$studyExportRoutes = require __DIR__.'/api/study-exports.php';

/** @var callable(): void $studyImportRoutes */
$studyImportRoutes = require __DIR__.'/api/study-imports.php';

Route::middleware('auth:sanctum')->group(function () use ($adminRoutes, $authRoutes, $cardRoutes, $contentRoutes, $courseRoutes, $deckRoutes, $featureFlagRoutes, $mediaAssetRoutes, $reviewRoutes, $studyBrowserRoutes, $studyDailyAudioRoutes, $studyExportRoutes, $studyImportRoutes, $syncFeedRoutes): void {
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
        ->group(function () use ($studyBrowserRoutes, $studyDailyAudioRoutes, $studyExportRoutes, $studyImportRoutes): void {
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
            Route::get('/study/card-drafts', ListStudyCardDraftsController::class)
                ->middleware('throttle:'.StudyCompatibilityTrafficRateLimiter::READ_NAME);
            Route::get('/study/card-drafts/{draftId}', ShowStudyCardDraftController::class)
                ->whereUlid('draftId')
                ->middleware('throttle:'.StudyCompatibilityTrafficRateLimiter::READ_NAME);
            // Drafts, commits, and final manual cards share one user-scoped request bucket.
            Route::post('/study/card-drafts/{draftId}/card', StoreStudyCardFromDraftController::class)
                ->whereUlid('draftId')
                ->middleware('throttle:'.StudyCardCreateRateLimiter::NAME);
            // ConvoLab path alias; this backend still requires a client card ID for retry-safe commits.
            Route::post('/study/card-drafts/{draftId}/create-card', StoreStudyCardFromDraftController::class)
                ->whereUlid('draftId')
                ->middleware('throttle:'.StudyCardCreateRateLimiter::NAME);
            Route::post('/study/card-drafts', StoreStudyCardDraftController::class)
                ->middleware('throttle:'.StudyCardCreateRateLimiter::NAME);
            Route::post('/study/card-candidates/vocab-bundle/drafts', StoreStudyVocabBundleDraftsController::class)
                ->middleware('throttle:'.StudyVocabBundleDraftRateLimiter::NAME);
            Route::patch('/study/card-drafts/{draftId}', UpdateStudyCardDraftController::class)
                ->whereUlid('draftId')
                ->middleware('throttle:'.StudyCardDraftAutosaveRateLimiter::NAME);
            // Provider actions consume one shared 10/min user spend budget after payload validation.
            Route::post('/study/card-drafts/{draftId}/preview-audio', GenerateStudyCardDraftPreviewAudioController::class)
                ->whereUlid('draftId');
            Route::post('/study/card-drafts/{draftId}/preview-image', GenerateStudyCardDraftPreviewImageController::class)
                ->whereUlid('draftId');
            Route::post('/study/cards/{cardId}/regenerate-answer-audio', RegenerateStudyCardAnswerAudioController::class)
                ->where('cardId', Card::CLIENT_ID_ROUTE_PATTERN);
            Route::post('/study/cards/{cardId}/regenerate-image', RegenerateStudyCardImageController::class)
                ->where('cardId', Card::CLIENT_ID_ROUTE_PATTERN);
            Route::post('/study/cards/{cardId}/image', UploadStudyCardImageController::class)
                ->where('cardId', Card::CLIENT_ID_ROUTE_PATTERN)
                ->middleware('throttle:'.StudyCardUpdateRateLimiter::NAME);
            Route::post('/study/cards/{cardId}/pitch-accent', ResolveStudyCardPitchAccentController::class)
                ->where('cardId', Card::CLIENT_ID_ROUTE_PATTERN)
                ->middleware('throttle:'.StudyCardPitchAccentRateLimiter::NAME);
            Route::post('/study/cards/{cardId}/prepare-answer-audio', PrepareStudyCardAnswerAudioController::class)
                ->where('cardId', Card::CLIENT_ID_ROUTE_PATTERN)
                ->middleware('throttle:'.StudyCardAudioPrepareRateLimiter::NAME);
            // Manual generation retries use their own 30/min user bucket so create/autosave retries cannot starve them.
            Route::post('/study/card-drafts/{draftId}/retry', RetryStudyCardDraftController::class)
                ->whereUlid('draftId')
                ->middleware('throttle:'.StudyCardDraftRetryRateLimiter::NAME);
            Route::delete('/study/card-drafts/{draftId}', DeleteStudyCardDraftController::class)
                ->whereUlid('draftId')
                ->middleware('throttle:'.StudyCardDraftDeleteRateLimiter::NAME);
            Route::get('/study/new-queue', ListStudyNewCardQueueController::class)
                ->middleware('throttle:'.StudyCompatibilityTrafficRateLimiter::READ_NAME);
            Route::get('/study/cards', ListStudyCardsController::class)
                ->middleware('throttle:'.StudyCompatibilityTrafficRateLimiter::READ_NAME);
            // Sync clients resolve one feed page per request instead of spending the
            // shared compatibility quota on one card-detail request per feed entry.
            Route::post('/study/cards/batch', ListStudyCardBatchController::class)
                ->middleware('throttle:'.StudyCompatibilityTrafficRateLimiter::READ_NAME);
            Route::get('/study/cards/{cardId}', ShowStudyCardController::class)
                ->where('cardId', Card::CLIENT_ID_ROUTE_PATTERN)
                ->middleware('throttle:'.StudyCompatibilityTrafficRateLimiter::READ_NAME);
            // Shares the canonical new-card queue reorder rate limit above.
            Route::post('/study/new-queue/reorder', ReorderStudyNewCardQueueController::class)
                ->middleware('throttle:'.NewCardQueueReorderRateLimiter::NAME);
            Route::get('/study/overview', ShowStudyOverviewController::class)
                ->middleware('throttle:'.StudyCompatibilityTrafficRateLimiter::READ_NAME);
            Route::get('/study/activity-sessions', ListStudyActivitySessionsController::class)
                ->middleware('throttle:'.StudyCompatibilityTrafficRateLimiter::READ_NAME);
            Route::get('/study/activity-analytics', ShowStudyActivityAnalyticsController::class)
                ->middleware('throttle:'.StudyCompatibilityTrafficRateLimiter::READ_NAME);
            Route::post('/study/activity-sessions/batch', StoreStudyActivitySessionsController::class)
                ->middleware('throttle:'.StudyActivitySessionRateLimiter::NAME);
            Route::delete('/study/activity-sessions/{clientSessionId}', DeleteStudyActivitySessionController::class)
                ->where('clientSessionId', '(?:[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}|[0-9a-fA-F-]{36})')
                ->middleware('throttle:'.StudyActivitySessionRateLimiter::NAME);
            // Shares the canonical review-create rate limit above.
            Route::post('/study/reviews', StoreStudyReviewController::class)
                ->middleware('throttle:'.CardReviewEventCreateRateLimiter::NAME);
            // Shares the canonical review-undo rate limit above, separate from review creates.
            Route::post('/study/reviews/undo', StoreStudyReviewUndoController::class)
                ->middleware('throttle:'.CardReviewEventUndoRateLimiter::NAME);
            Route::delete('/study/reviews/{reviewLogId}', UndoStudyReviewController::class)
                ->whereUlid('reviewLogId')
                ->middleware('throttle:'.CardReviewEventUndoRateLimiter::NAME);
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

unset($adminRoutes, $authRoutes, $cardRoutes, $contentRoutes, $courseRoutes, $deckRoutes, $featureFlagRoutes, $mediaAssetRoutes, $publicMediaAnalyticsRoutes, $reviewRoutes, $studyBrowserRoutes, $studyDailyAudioRoutes, $studyExportRoutes, $studyImportRoutes, $syncFeedRoutes);
