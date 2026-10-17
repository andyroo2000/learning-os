<?php

use App\Domain\Content\Support\ContentAudioScriptRateLimiter;
use App\Domain\Content\Support\ContentCourseRateLimiter;
use App\Domain\Content\Support\ContentEpisodeRateLimiter;
use App\Http\Controllers\Api\Content\DeleteContentCourseController;
use App\Http\Controllers\Api\Content\DeleteContentEpisodeController;
use App\Http\Controllers\Api\Content\DownloadContentAudioScriptMediaController;
use App\Http\Controllers\Api\Content\DownloadContentAudioScriptRenderController;
use App\Http\Controllers\Api\Content\DownloadContentCourseAudioController;
use App\Http\Controllers\Api\Content\DownloadContentEpisodeAudioController;
use App\Http\Controllers\Api\Content\ListContentCoursesController;
use App\Http\Controllers\Api\Content\ListContentEpisodesController;
use App\Http\Controllers\Api\Content\ShowContentAudioScriptController;
use App\Http\Controllers\Api\Content\ShowContentCourseController;
use App\Http\Controllers\Api\Content\ShowContentEpisodeController;
use App\Http\Controllers\Api\Content\UpdateContentCourseController;
use App\Http\Controllers\Api\Content\UpdateContentEpisodeController;
use Illuminate\Support\Facades\Route;

return static function (): void {
    Route::get('/convolab/episodes', ListContentEpisodesController::class);
    Route::get('/convolab/episodes/{episodeId}', ShowContentEpisodeController::class)
        ->whereUuid('episodeId');
    Route::patch('/convolab/episodes/{episodeId}', UpdateContentEpisodeController::class)
        ->whereUuid('episodeId')
        ->middleware('throttle:'.ContentEpisodeRateLimiter::UPDATE_NAME);
    Route::delete('/convolab/episodes/{episodeId}', DeleteContentEpisodeController::class)
        ->whereUuid('episodeId')
        ->middleware('throttle:'.ContentEpisodeRateLimiter::DELETE_NAME);
    Route::get('/convolab/episodes/{episodeId}/audio/{track}', DownloadContentEpisodeAudioController::class)
        ->whereUuid('episodeId')
        ->whereIn('track', ['default', '0.7', '0.85', '1.0']);
    Route::get('/convolab/scripts/media/{mediaId}', DownloadContentAudioScriptMediaController::class)
        ->whereUuid('mediaId')
        ->middleware('throttle:'.ContentAudioScriptRateLimiter::MEDIA_READ_NAME);
    Route::get('/convolab/scripts/{episodeId}/status', ShowContentAudioScriptController::class)
        ->whereUuid('episodeId');
    Route::get('/convolab/scripts/{episodeId}/audio/{renderId}', DownloadContentAudioScriptRenderController::class)
        ->whereUuid('episodeId')
        ->whereUuid('renderId')
        ->middleware('throttle:'.ContentAudioScriptRateLimiter::MEDIA_READ_NAME);
    Route::get('/convolab/courses', ListContentCoursesController::class);
    Route::get('/convolab/courses/{courseId}', ShowContentCourseController::class)
        ->whereUuid('courseId');
    Route::patch('/convolab/courses/{courseId}', UpdateContentCourseController::class)
        ->whereUuid('courseId')
        ->middleware('throttle:'.ContentCourseRateLimiter::UPDATE_NAME);
    Route::delete('/convolab/courses/{courseId}', DeleteContentCourseController::class)
        ->whereUuid('courseId')
        ->middleware('throttle:'.ContentCourseRateLimiter::DELETE_NAME);
    Route::get('/convolab/courses/{courseId}/audio', DownloadContentCourseAudioController::class)
        ->whereUuid('courseId');
};
