<?php

use App\Domain\Auth\Support\AuthAccountRateLimiter;
use App\Domain\Auth\Support\AuthEmailRateLimiter;
use App\Domain\Auth\Support\ConvoLabProfileRateLimiter;
use App\Domain\Auth\Support\ConvoLabVerificationRateLimiter;
use App\Domain\Content\Support\ContentAudioRateLimiter;
use App\Domain\Content\Support\ContentAudioScriptRateLimiter;
use App\Domain\Content\Support\ContentCourseRateLimiter;
use App\Domain\Content\Support\ContentDialogueRateLimiter;
use App\Domain\Content\Support\ContentEpisodeRateLimiter;
use App\Domain\Content\Support\ContentImageRateLimiter;
use App\Domain\Courses\Support\CourseRateLimiter;
use App\Domain\FeatureFlags\Support\FeatureFlagUpdateRateLimiter;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Support\DeckRateLimiter;
use App\Domain\Flashcards\Support\NewCardQueueReorderRateLimiter;
use App\Domain\Japanese\Support\JapaneseKnowledgeRateLimiter;
use App\Domain\Media\Support\CardMediaRateLimiter;
use App\Domain\Media\Support\MediaAssetRateLimiter;
use App\Domain\Media\Support\ToolAudioSignedUrlRateLimiter;
use App\Domain\Reviews\Support\CardReviewEventCreateRateLimiter;
use App\Domain\Reviews\Support\CardReviewEventUndoRateLimiter;
use App\Domain\Study\Support\DailyAudioPracticeGenerationRateLimiter;
use App\Domain\Study\Support\StudyCardActionRateLimiter;
use App\Domain\Study\Support\StudyCardAudioPrepareRateLimiter;
use App\Domain\Study\Support\StudyCardCreateRateLimiter;
use App\Domain\Study\Support\StudyCardDeleteRateLimiter;
use App\Domain\Study\Support\StudyCardDraftAutosaveRateLimiter;
use App\Domain\Study\Support\StudyCardDraftDeleteRateLimiter;
use App\Domain\Study\Support\StudyCardDraftRetryRateLimiter;
use App\Domain\Study\Support\StudyCardPitchAccentRateLimiter;
use App\Domain\Study\Support\StudyCardUpdateRateLimiter;
use App\Domain\Study\Support\StudyImportRateLimiter;
use App\Domain\Study\Support\StudySessionStartRateLimiter;
use App\Domain\Study\Support\StudySettingsUpdateRateLimiter;
use App\Domain\Study\Support\StudyVocabBundleDraftRateLimiter;
use App\Http\Controllers\Api\Admin\ListAdminInviteCodesController;
use App\Http\Controllers\Api\Admin\ListAdminUsersController;
use App\Http\Controllers\Api\Admin\ShowAdminStatsController;
use App\Http\Controllers\Api\Admin\ShowAdminUserController;
use App\Http\Controllers\Api\Auth\AuthenticateConvoLabUserController;
use App\Http\Controllers\Api\Auth\DeleteCurrentUserController;
use App\Http\Controllers\Api\Auth\DestroyAccessTokenController;
use App\Http\Controllers\Api\Auth\DestroyCurrentAccessTokenController;
use App\Http\Controllers\Api\Auth\ListAccessTokensController;
use App\Http\Controllers\Api\Auth\RegisterConvoLabUserController;
use App\Http\Controllers\Api\Auth\RegisterMobileUserController;
use App\Http\Controllers\Api\Auth\ResetUserPasswordController;
use App\Http\Controllers\Api\Auth\SendConvoLabVerificationController;
use App\Http\Controllers\Api\Auth\SendPasswordResetLinkController;
use App\Http\Controllers\Api\Auth\ShowConvoLabCurrentUserController;
use App\Http\Controllers\Api\Auth\ShowCurrentUserController;
use App\Http\Controllers\Api\Auth\StoreMobileTokenController;
use App\Http\Controllers\Api\Auth\UpdateConvoLabCurrentUserController;
use App\Http\Controllers\Api\Auth\UpdateCurrentUserPasswordController;
use App\Http\Controllers\Api\Auth\UpdateCurrentUserProfileController;
use App\Http\Controllers\Api\Auth\VerifyConvoLabEmailController;
use App\Http\Controllers\Api\Content\AnnotateContentAudioScriptController;
use App\Http\Controllers\Api\Content\DeleteContentCourseController;
use App\Http\Controllers\Api\Content\DeleteContentEpisodeController;
use App\Http\Controllers\Api\Content\DownloadContentAudioScriptMediaController;
use App\Http\Controllers\Api\Content\DownloadContentAudioScriptRenderController;
use App\Http\Controllers\Api\Content\DownloadContentCourseAudioController;
use App\Http\Controllers\Api\Content\DownloadContentEpisodeAudioController;
use App\Http\Controllers\Api\Content\GenerateAllSpeedsContentAudioController;
use App\Http\Controllers\Api\Content\GenerateContentAudioController;
use App\Http\Controllers\Api\Content\GenerateContentAudioScriptImagesController;
use App\Http\Controllers\Api\Content\GenerateContentAudioScriptRenderController;
use App\Http\Controllers\Api\Content\GenerateContentCourseController;
use App\Http\Controllers\Api\Content\GenerateContentDialogueController;
use App\Http\Controllers\Api\Content\GenerateContentImagesController;
use App\Http\Controllers\Api\Content\ListContentCoursesController;
use App\Http\Controllers\Api\Content\ListContentEpisodesController;
use App\Http\Controllers\Api\Content\ResetContentCourseGenerationController;
use App\Http\Controllers\Api\Content\RetryContentCourseGenerationController;
use App\Http\Controllers\Api\Content\ShowContentAudioGenerationJobController;
use App\Http\Controllers\Api\Content\ShowContentAudioScriptController;
use App\Http\Controllers\Api\Content\ShowContentAudioScriptGenerationJobController;
use App\Http\Controllers\Api\Content\ShowContentCourseController;
use App\Http\Controllers\Api\Content\ShowContentCourseGenerationStatusController;
use App\Http\Controllers\Api\Content\ShowContentDialogueGenerationJobController;
use App\Http\Controllers\Api\Content\ShowContentEpisodeController;
use App\Http\Controllers\Api\Content\ShowContentImageGenerationJobController;
use App\Http\Controllers\Api\Content\StoreContentAudioScriptController;
use App\Http\Controllers\Api\Content\StoreContentCourseController;
use App\Http\Controllers\Api\Content\StoreContentEpisodeController;
use App\Http\Controllers\Api\Content\UpdateContentAudioScriptSegmentsController;
use App\Http\Controllers\Api\Content\UpdateContentCourseController;
use App\Http\Controllers\Api\Content\UpdateContentEpisodeController;
use App\Http\Controllers\Api\Courses\DeleteCourseController;
use App\Http\Controllers\Api\Courses\ListCoursesController;
use App\Http\Controllers\Api\Courses\ShowCourseController;
use App\Http\Controllers\Api\Courses\StoreCourseController;
use App\Http\Controllers\Api\Courses\UpdateCourseController;
use App\Http\Controllers\Api\FeatureFlags\ShowFeatureFlagsController;
use App\Http\Controllers\Api\FeatureFlags\UpdateFeatureFlagsController;
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
use App\Http\Controllers\Api\Media\ResolveToolAudioUrlsController;
use App\Http\Controllers\Api\Media\ShowAvatarAssetController;
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
use App\Http\Controllers\Api\Study\ConnectWaniKaniController;
use App\Http\Controllers\Api\Study\DeleteStudyCardController;
use App\Http\Controllers\Api\Study\DeleteStudyCardDraftController;
use App\Http\Controllers\Api\Study\DisconnectWaniKaniController;
use App\Http\Controllers\Api\Study\DownloadDailyAudioPracticeTrackController;
use App\Http\Controllers\Api\Study\GenerateStudyCardDraftPreviewAudioController;
use App\Http\Controllers\Api\Study\GenerateStudyCardDraftPreviewImageController;
use App\Http\Controllers\Api\Study\ListDailyAudioPracticesController;
use App\Http\Controllers\Api\Study\ListStudyBrowserController;
use App\Http\Controllers\Api\Study\ListStudyCardDraftsController;
use App\Http\Controllers\Api\Study\ListStudyExportCardDraftsController;
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
use App\Http\Controllers\Api\Study\PrepareStudyCardAnswerAudioController;
use App\Http\Controllers\Api\Study\RegenerateStudyCardAnswerAudioController;
use App\Http\Controllers\Api\Study\RegenerateStudyCardImageController;
use App\Http\Controllers\Api\Study\ReorderStudyNewCardQueueController;
use App\Http\Controllers\Api\Study\ResolveStudyCardPitchAccentController;
use App\Http\Controllers\Api\Study\RetryStudyCardDraftController;
use App\Http\Controllers\Api\Study\SetManualKnownKanjiController;
use App\Http\Controllers\Api\Study\ShowCurrentStudyImportJobController;
use App\Http\Controllers\Api\Study\ShowDailyAudioPracticeController;
use App\Http\Controllers\Api\Study\ShowDailyAudioPracticeStatusController;
use App\Http\Controllers\Api\Study\ShowKnownKanjiController;
use App\Http\Controllers\Api\Study\ShowStudyBrowserNoteController;
use App\Http\Controllers\Api\Study\ShowStudyCardDraftController;
use App\Http\Controllers\Api\Study\ShowStudyExportManifestController;
use App\Http\Controllers\Api\Study\ShowStudyExportSettingsController;
use App\Http\Controllers\Api\Study\ShowStudyImportJobController;
use App\Http\Controllers\Api\Study\ShowStudyImportReadinessController;
use App\Http\Controllers\Api\Study\ShowStudyOverviewController;
use App\Http\Controllers\Api\Study\ShowStudySettingsController;
use App\Http\Controllers\Api\Study\StartStudySessionController;
use App\Http\Controllers\Api\Study\StoreDailyAudioPracticeController;
use App\Http\Controllers\Api\Study\StoreStudyCardController;
use App\Http\Controllers\Api\Study\StoreStudyCardDraftController;
use App\Http\Controllers\Api\Study\StoreStudyCardFromDraftController;
use App\Http\Controllers\Api\Study\StoreStudyImportController;
use App\Http\Controllers\Api\Study\StoreStudyReviewController;
use App\Http\Controllers\Api\Study\StoreStudyReviewUndoController;
use App\Http\Controllers\Api\Study\StoreStudyVocabBundleDraftsController;
use App\Http\Controllers\Api\Study\SyncWaniKaniKanjiController;
use App\Http\Controllers\Api\Study\UndoStudyReviewController;
use App\Http\Controllers\Api\Study\UpdateStudyCardController;
use App\Http\Controllers\Api\Study\UpdateStudyCardDraftController;
use App\Http\Controllers\Api\Study\UpdateStudySettingsController;
use App\Http\Controllers\Api\Study\UploadStudyImportFileController;
use App\Http\Controllers\Api\Sync\ListSyncFeedEntriesController;
use Illuminate\Support\Facades\Route;

// Public static learning media stays path-allowlisted and rate-limited where URLs are batched.
Route::get('/avatars/{avatarPath}', ShowAvatarAssetController::class)
    ->where('avatarPath', '.*');
Route::post('/tools-audio/signed-urls', ResolveToolAudioUrlsController::class)
    ->middleware('throttle:'.ToolAudioSignedUrlRateLimiter::NAME);

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
    Route::post('/convolab/auth/login', AuthenticateConvoLabUserController::class)
        ->middleware('throttle:'.AuthEmailRateLimiter::CONVOLAB_LOGINS);
    Route::post('/convolab/auth/signup', RegisterConvoLabUserController::class)
        ->middleware('throttle:'.AuthEmailRateLimiter::CONVOLAB_SIGNUPS);
    Route::get('/convolab/auth/me', ShowConvoLabCurrentUserController::class);
    Route::patch('/convolab/auth/me', UpdateConvoLabCurrentUserController::class)
        ->middleware('throttle:'.ConvoLabProfileRateLimiter::NAME);
    Route::post('/convolab/auth/verification/send', SendConvoLabVerificationController::class)
        ->middleware('throttle:'.ConvoLabVerificationRateLimiter::SEND);
    Route::post('/convolab/auth/verification', VerifyConvoLabEmailController::class)
        ->middleware('throttle:'.ConvoLabVerificationRateLimiter::VERIFY);
    Route::get('/convolab/admin/stats', ShowAdminStatsController::class);
    Route::get('/convolab/admin/users', ListAdminUsersController::class);
    Route::get('/convolab/admin/users/{convoLabUserId}/info', ShowAdminUserController::class)
        ->whereUuid('convoLabUserId');
    Route::get('/convolab/admin/invite-codes', ListAdminInviteCodesController::class);
    Route::get('/convolab/episodes', ListContentEpisodesController::class);
    Route::post('/convolab/episodes', StoreContentEpisodeController::class)
        ->middleware('throttle:'.ContentEpisodeRateLimiter::CREATE_NAME);
    Route::get('/convolab/episodes/{episodeId}', ShowContentEpisodeController::class)
        ->whereUuid('episodeId');
    Route::patch('/convolab/episodes/{episodeId}', UpdateContentEpisodeController::class)
        ->whereUuid('episodeId')
        ->middleware('throttle:'.ContentEpisodeRateLimiter::UPDATE_NAME);
    Route::delete('/convolab/episodes/{episodeId}', DeleteContentEpisodeController::class)
        ->whereUuid('episodeId')
        ->middleware('throttle:'.ContentEpisodeRateLimiter::DELETE_NAME);
    Route::post('/convolab/dialogue/generate', GenerateContentDialogueController::class)
        ->middleware('throttle:'.ContentDialogueRateLimiter::GENERATION_NAME);
    Route::get('/convolab/dialogue/job/{jobId}', ShowContentDialogueGenerationJobController::class)
        ->whereUuid('jobId');
    Route::post('/convolab/images/generate', GenerateContentImagesController::class)
        ->middleware('throttle:'.ContentImageRateLimiter::GENERATION_NAME);
    Route::get('/convolab/images/job/{jobId}', ShowContentImageGenerationJobController::class)
        ->whereUuid('jobId');
    // Single and bulk synthesis deliberately share one per-user capacity bucket.
    Route::post('/convolab/audio/generate', GenerateContentAudioController::class)
        ->middleware('throttle:'.ContentAudioRateLimiter::GENERATION_NAME);
    Route::post('/convolab/audio/generate-all-speeds', GenerateAllSpeedsContentAudioController::class)
        ->middleware('throttle:'.ContentAudioRateLimiter::GENERATION_NAME);
    Route::get('/convolab/audio/job/{jobId}', ShowContentAudioGenerationJobController::class)
        ->whereUuid('jobId');
    Route::get('/convolab/episodes/{episodeId}/audio/{track}', DownloadContentEpisodeAudioController::class)
        ->whereUuid('episodeId')
        ->whereIn('track', ['default', '0.7', '0.85', '1.0']);
    Route::get('/convolab/scripts/media/{mediaId}', DownloadContentAudioScriptMediaController::class)
        ->whereUuid('mediaId')
        ->middleware('throttle:'.ContentAudioScriptRateLimiter::MEDIA_READ_NAME);
    Route::post('/convolab/scripts', StoreContentAudioScriptController::class)
        ->middleware('throttle:'.ContentAudioScriptRateLimiter::GENERATION_NAME);
    Route::post('/convolab/scripts/{episodeId}/annotate', AnnotateContentAudioScriptController::class)
        ->whereUuid('episodeId')
        ->middleware('throttle:'.ContentAudioScriptRateLimiter::GENERATION_NAME);
    Route::patch('/convolab/scripts/{episodeId}/segments', UpdateContentAudioScriptSegmentsController::class)
        ->whereUuid('episodeId')
        ->middleware('throttle:'.ContentAudioScriptRateLimiter::UPDATE_NAME);
    Route::post('/convolab/scripts/{episodeId}/render', GenerateContentAudioScriptRenderController::class)
        ->whereUuid('episodeId')
        ->middleware('throttle:'.ContentAudioScriptRateLimiter::GENERATION_NAME);
    Route::post('/convolab/scripts/{episodeId}/images', GenerateContentAudioScriptImagesController::class)
        ->whereUuid('episodeId')
        ->middleware('throttle:'.ContentAudioScriptRateLimiter::GENERATION_NAME);
    Route::get('/convolab/scripts/{episodeId}/status', ShowContentAudioScriptController::class)
        ->whereUuid('episodeId');
    Route::get('/convolab/scripts/job/{jobId}', ShowContentAudioScriptGenerationJobController::class)
        ->whereUuid('jobId');
    Route::get('/convolab/scripts/{episodeId}/audio/{renderId}', DownloadContentAudioScriptRenderController::class)
        ->whereUuid('episodeId')
        ->whereUuid('renderId')
        ->middleware('throttle:'.ContentAudioScriptRateLimiter::MEDIA_READ_NAME);
    Route::get('/convolab/courses', ListContentCoursesController::class);
    Route::post('/convolab/courses', StoreContentCourseController::class)
        ->middleware('throttle:'.ContentCourseRateLimiter::CREATE_NAME);
    Route::get('/convolab/courses/{courseId}', ShowContentCourseController::class)
        ->whereUuid('courseId');
    Route::patch('/convolab/courses/{courseId}', UpdateContentCourseController::class)
        ->whereUuid('courseId')
        ->middleware('throttle:'.ContentCourseRateLimiter::UPDATE_NAME);
    Route::delete('/convolab/courses/{courseId}', DeleteContentCourseController::class)
        ->whereUuid('courseId')
        ->middleware('throttle:'.ContentCourseRateLimiter::DELETE_NAME);
    Route::post('/convolab/courses/{courseId}/generate', GenerateContentCourseController::class)
        ->whereUuid('courseId')
        ->middleware('throttle:'.ContentCourseRateLimiter::GENERATION_NAME);
    Route::get('/convolab/courses/{courseId}/status', ShowContentCourseGenerationStatusController::class)
        ->whereUuid('courseId');
    Route::post('/convolab/courses/{courseId}/reset', ResetContentCourseGenerationController::class)
        ->whereUuid('courseId')
        ->middleware('throttle:'.ContentCourseRateLimiter::RESET_NAME);
    Route::post('/convolab/courses/{courseId}/retry', RetryContentCourseGenerationController::class)
        ->whereUuid('courseId')
        ->middleware('throttle:'.ContentCourseRateLimiter::GENERATION_NAME);
    Route::get('/convolab/courses/{courseId}/audio', DownloadContentCourseAudioController::class)
        ->whereUuid('courseId');
    Route::get('/feature-flags', ShowFeatureFlagsController::class);
    Route::patch('/feature-flags', UpdateFeatureFlagsController::class)
        ->middleware('throttle:'.FeatureFlagUpdateRateLimiter::NAME);
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
    // Course creates, updates, and deletes below have separate buckets so create retries cannot starve destructive actions.
    Route::get('/courses', ListCoursesController::class);
    Route::post('/courses', StoreCourseController::class)
        ->middleware('throttle:'.CourseRateLimiter::CREATE_NAME);
    Route::get('/courses/{course}', ShowCourseController::class)->whereUlid('course');
    Route::put('/courses/{course}', UpdateCourseController::class)
        ->whereUlid('course')
        ->middleware('throttle:'.CourseRateLimiter::UPDATE_NAME);
    Route::delete('/courses/{course}', DeleteCourseController::class)
        ->whereUlid('course')
        ->middleware('throttle:'.CourseRateLimiter::DELETE_NAME);
    Route::get('/card-review-events', ListReviewEventsController::class);
    // Review creates, batch replay, and study create aliases share one request-based create quota.
    // Batch payload size remains capped at 500 events by request validation.
    Route::post('/card-review-events/batch', StoreCardReviewEventBatchController::class)
        ->middleware('throttle:'.CardReviewEventCreateRateLimiter::NAME);
    Route::get('/card-review-events/{cardReviewEvent}', ShowCardReviewEventController::class)->whereUlid('cardReviewEvent');
    // Review undo hard-deletes the event; DELETE retries for already-undone events resolve as 404.
    Route::delete('/card-review-events/{cardReviewEvent}', UndoCardReviewEventController::class)
        ->whereUlid('cardReviewEvent')
        ->middleware('throttle:'.CardReviewEventUndoRateLimiter::NAME);
    Route::post('/card-review-events', StoreCardReviewEventController::class)
        ->middleware('throttle:'.CardReviewEventCreateRateLimiter::NAME);
    Route::get('/cards/due', ListDueCardsController::class);
    Route::get('/cards/new', ListNewCardsController::class);
    // Canonical and ConvoLab queue reorders share one user-scoped quota for the same mutation.
    Route::post('/cards/new/reorder', ReorderNewCardQueueController::class)
        ->middleware('throttle:'.NewCardQueueReorderRateLimiter::NAME);
    Route::get('/cards/{card}', ShowCardController::class)->whereUlid('card');
    Route::get('/cards/{card}/review-events', ListCardReviewEventsController::class)->whereUlid('card');
    Route::get('/cards/{card}/media-assets', ListCardMediaAssetsController::class)->whereUlid('card');
    // Card-media relation writes have their own retry-friendly quotas separate from card content writes.
    Route::post('/cards/{card}/media-assets', AttachMediaToCardController::class)
        ->whereUlid('card')
        ->middleware('throttle:'.CardMediaRateLimiter::ATTACH_NAME);
    Route::delete('/cards/{card}/media-assets/{mediaAsset}', DetachMediaFromCardController::class)
        ->whereUlid('card')
        ->whereUlid('mediaAsset')
        ->middleware('throttle:'.CardMediaRateLimiter::DETACH_NAME);
    Route::get('/cards', ListCardsController::class);
    // Canonical and study card writes share quotas because they mutate the same card resources.
    Route::post('/cards', StoreCardController::class)
        ->middleware('throttle:'.StudyCardCreateRateLimiter::NAME);
    Route::post('/cards/{card}/actions', PerformCardStudyActionController::class)
        ->whereUlid('card')
        ->middleware('throttle:'.StudyCardActionRateLimiter::NAME);
    Route::patch('/cards/{card}/study-status', UpdateCardStudyStatusController::class)
        ->whereUlid('card')
        ->middleware('throttle:'.StudyCardUpdateRateLimiter::NAME);
    Route::put('/cards/{card}', UpdateCardController::class)
        ->whereUlid('card')
        ->middleware('throttle:'.StudyCardUpdateRateLimiter::NAME);
    Route::delete('/cards/{card}', DeleteCardController::class)
        ->whereUlid('card')
        ->middleware('throttle:'.StudyCardDeleteRateLimiter::NAME);
    Route::get('/media-assets', ListMediaAssetsController::class);
    Route::post('/media-assets', StoreMediaAssetController::class)
        ->middleware('throttle:'.MediaAssetRateLimiter::CREATE_NAME);
    Route::get('/media-assets/{mediaAsset}/content', DownloadMediaAssetContentController::class)
        ->name('api.media-assets.content');
    Route::get('/media-assets/{mediaAsset}', ShowMediaAssetController::class);
    // Use a raw ID segment so missing/cross-user media assets stay idempotent 204s.
    Route::delete('/media-assets/{mediaAssetId}', DeleteMediaAssetController::class)
        ->middleware('throttle:'.MediaAssetRateLimiter::DELETE_NAME);
    Route::get('/sync/feed', ListSyncFeedEntriesController::class);
    Route::post('/study/session/start', StartStudySessionController::class)
        ->middleware('throttle:'.StudySessionStartRateLimiter::NAME);
    Route::post('/daily-audio-practice', StoreDailyAudioPracticeController::class)
        ->middleware('throttle:'.DailyAudioPracticeGenerationRateLimiter::NAME);
    Route::get('/daily-audio-practice', ListDailyAudioPracticesController::class);
    Route::get('/daily-audio-practice/{practiceId}', ShowDailyAudioPracticeController::class)
        ->whereUuid('practiceId');
    Route::get('/daily-audio-practice/{practiceId}/status', ShowDailyAudioPracticeStatusController::class)
        ->whereUuid('practiceId');
    Route::get(
        '/daily-audio-practice/{practiceId}/tracks/{trackId}/audio',
        DownloadDailyAudioPracticeTrackController::class,
    )
        ->whereUuid('practiceId')
        ->whereUuid('trackId');
    Route::get('/study/export', ShowStudyExportManifestController::class);
    Route::get('/study/export/card-drafts', ListStudyExportCardDraftsController::class)->name('api.study.export.card-drafts');
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
    // Study import lifecycle writes have separate quotas so upload retries do not starve cancel/complete.
    Route::post('/study/imports', StoreStudyImportController::class)
        ->middleware('throttle:'.StudyImportRateLimiter::CREATE_NAME);
    Route::get('/study/imports/readiness', ShowStudyImportReadinessController::class);
    Route::get('/study/imports/current', ShowCurrentStudyImportJobController::class);
    Route::put('/study/imports/{studyImportJobId}/upload', UploadStudyImportFileController::class)
        ->whereUlid('studyImportJobId')
        ->name('api.study.imports.upload')
        ->middleware('throttle:'.StudyImportRateLimiter::UPLOAD_NAME);
    Route::post('/study/imports/{studyImportJobId}/complete', CompleteStudyImportUploadController::class)
        ->whereUlid('studyImportJobId')
        ->middleware('throttle:'.StudyImportRateLimiter::COMPLETE_NAME);
    Route::post('/study/imports/{studyImportJobId}/cancel', CancelStudyImportUploadController::class)
        ->whereUlid('studyImportJobId')
        ->middleware('throttle:'.StudyImportRateLimiter::CANCEL_NAME);
    Route::get('/study/imports/{studyImportJobId}', ShowStudyImportJobController::class)
        // Copied ConvoLab jobs retain UUIDs; jobs created by Learning OS use ULIDs.
        ->where('studyImportJobId', '(?:[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}|[0-9a-fA-F]{8}(?:-[0-9a-fA-F]{4}){3}-[0-9a-fA-F]{12})');
    Route::get('/study/browser', ListStudyBrowserController::class);
    // Supports numeric Anki IDs, native ULIDs, and copied ConvoLab UUIDs.
    Route::get('/study/browser/{noteId}', ShowStudyBrowserNoteController::class)->where('noteId', '[A-Za-z0-9-]+');
    Route::get('/study/card-drafts', ListStudyCardDraftsController::class);
    Route::get('/study/card-drafts/{draftId}', ShowStudyCardDraftController::class)->whereUlid('draftId');
    // Draft creation, draft commits, and final manual-card creation share one user-scoped creation quota.
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
    Route::get('/study/new-queue', ListStudyNewCardQueueController::class);
    // Shares the canonical new-card queue reorder quota above.
    Route::post('/study/new-queue/reorder', ReorderStudyNewCardQueueController::class)
        ->middleware('throttle:'.NewCardQueueReorderRateLimiter::NAME);
    Route::get('/study/overview', ShowStudyOverviewController::class);
    // Shares the canonical review-create quota above.
    Route::post('/study/reviews', StoreStudyReviewController::class)
        ->middleware('throttle:'.CardReviewEventCreateRateLimiter::NAME);
    // Shares the canonical review-undo quota above, separate from review creates.
    Route::post('/study/reviews/undo', StoreStudyReviewUndoController::class)
        ->middleware('throttle:'.CardReviewEventUndoRateLimiter::NAME);
    Route::delete('/study/reviews/{reviewLogId}', UndoStudyReviewController::class)
        ->whereUlid('reviewLogId')
        ->middleware('throttle:'.CardReviewEventUndoRateLimiter::NAME);
    // Shares the study-card creation quota with draft creation and draft commits.
    Route::post('/study/cards', StoreStudyCardController::class)
        ->middleware('throttle:'.StudyCardCreateRateLimiter::NAME);
    Route::delete('/study/cards/{cardId}', DeleteStudyCardController::class)
        ->where('cardId', Card::CLIENT_ID_ROUTE_PATTERN)
        ->middleware('throttle:'.StudyCardDeleteRateLimiter::NAME);
    // Manual card actions can be retried independently from create/update/delete writes.
    Route::post('/study/cards/{cardId}/actions', PerformStudyCardActionController::class)
        ->where('cardId', Card::CLIENT_ID_ROUTE_PATTERN)
        ->middleware('throttle:'.StudyCardActionRateLimiter::NAME);
    // Saved-card edits can be retried by sync clients; keep their quota separate from creation.
    Route::patch('/study/cards/{cardId}', UpdateStudyCardController::class)
        ->where('cardId', Card::CLIENT_ID_ROUTE_PATTERN)
        ->middleware('throttle:'.StudyCardUpdateRateLimiter::NAME);
    Route::get('/study/media/{mediaAsset}', DownloadMediaAssetContentController::class)->whereUlid('mediaAsset');
    Route::get('/study/settings', ShowStudySettingsController::class);
    // Settings sync can retry updates; keep that quota separate from card writes.
    Route::patch('/study/settings', UpdateStudySettingsController::class)
        ->middleware('throttle:'.StudySettingsUpdateRateLimiter::NAME);
    Route::get('/study/known-kanji', ShowKnownKanjiController::class);
    Route::patch('/study/known-kanji/manual', SetManualKnownKanjiController::class)
        ->middleware('throttle:'.JapaneseKnowledgeRateLimiter::MANUAL_NAME);
    Route::put('/study/wanikani', ConnectWaniKaniController::class)
        ->middleware('throttle:'.JapaneseKnowledgeRateLimiter::CONNECTION_NAME);
    Route::delete('/study/wanikani', DisconnectWaniKaniController::class)
        ->middleware('throttle:'.JapaneseKnowledgeRateLimiter::CONNECTION_NAME);
    Route::post('/study/wanikani/sync', SyncWaniKaniKanjiController::class)
        ->middleware('throttle:'.JapaneseKnowledgeRateLimiter::SYNC_NAME);
    // Deck updates and deletes use separate buckets from deck creation so replay pressure cannot starve deletes.
    Route::prefix('/decks/{deck}')
        ->whereUlid('deck')
        ->group(function (): void {
            Route::get('/', ShowDeckController::class);
            Route::get('/media-assets', ListDeckMediaAssetsController::class);
            Route::get('/cards', ListDeckCardsController::class);
            Route::put('/', UpdateDeckController::class)
                ->middleware('throttle:'.DeckRateLimiter::UPDATE_NAME);
            Route::delete('/', DeleteDeckController::class)
                ->middleware('throttle:'.DeckRateLimiter::DELETE_NAME);
        });
    Route::get('/decks', ListDecksController::class);
    // Deck creation has its own retryable write bucket; idempotent de-dupe runs inside the action.
    Route::post('/decks', StoreDeckController::class)
        ->middleware('throttle:'.DeckRateLimiter::CREATE_NAME);
});
