<?php

use App\Domain\Content\Support\ContentAudioRateLimiter;
use App\Domain\Content\Support\ContentAudioScriptRateLimiter;
use App\Domain\Content\Support\ContentCourseRateLimiter;
use App\Domain\Content\Support\ContentDialogueRateLimiter;
use App\Domain\Content\Support\ContentEpisodeRateLimiter;
use App\Domain\Content\Support\ContentImageRateLimiter;
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
use Illuminate\Support\Facades\Route;

return static function (): void {
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
};
