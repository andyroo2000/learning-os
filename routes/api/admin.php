<?php

use App\Domain\Admin\Support\AdminMutationRateLimiter;
use App\Http\Controllers\Api\Admin\BuildAdminCoursePromptController;
use App\Http\Controllers\Api\Admin\BuildAdminCourseScriptConfigController;
use App\Http\Controllers\Api\Admin\CreateAdminInviteCodeController;
use App\Http\Controllers\Api\Admin\DeleteAdminCourseLineRenderingController;
use App\Http\Controllers\Api\Admin\DeleteAdminInviteCodeController;
use App\Http\Controllers\Api\Admin\DeleteAdminScriptLabCoursesController;
use App\Http\Controllers\Api\Admin\DeleteAdminSentenceScriptTestsController;
use App\Http\Controllers\Api\Admin\DeleteAdminUserController;
use App\Http\Controllers\Api\Admin\DownloadAdminCourseLineRenderingController;
use App\Http\Controllers\Api\Admin\DownloadAdminScriptLabAudioController;
use App\Http\Controllers\Api\Admin\GenerateAdminCourseAudioController;
use App\Http\Controllers\Api\Admin\GenerateAdminCourseDialogueController;
use App\Http\Controllers\Api\Admin\GenerateAdminCourseScriptController;
use App\Http\Controllers\Api\Admin\GenerateAdminSentenceScriptController;
use App\Http\Controllers\Api\Admin\ListAdminCourseLineRenderingsController;
use App\Http\Controllers\Api\Admin\ListAdminInviteCodesController;
use App\Http\Controllers\Api\Admin\ListAdminScriptLabCoursesController;
use App\Http\Controllers\Api\Admin\ListAdminSentenceScriptTestsController;
use App\Http\Controllers\Api\Admin\ListAdminSpeakerAvatarsController;
use App\Http\Controllers\Api\Admin\ListAdminUsersController;
use App\Http\Controllers\Api\Admin\RecropAdminSpeakerAvatarController;
use App\Http\Controllers\Api\Admin\ShowAdminCoursePipelineController;
use App\Http\Controllers\Api\Admin\ShowAdminPronunciationDictionaryController;
use App\Http\Controllers\Api\Admin\ShowAdminScriptLabCourseController;
use App\Http\Controllers\Api\Admin\ShowAdminSentenceScriptTestController;
use App\Http\Controllers\Api\Admin\ShowAdminSpeakerAvatarOriginalController;
use App\Http\Controllers\Api\Admin\ShowAdminStatsController;
use App\Http\Controllers\Api\Admin\ShowAdminUserController;
use App\Http\Controllers\Api\Admin\StoreAdminScriptLabCourseController;
use App\Http\Controllers\Api\Admin\SynthesizeAdminCourseLineController;
use App\Http\Controllers\Api\Admin\SynthesizeAdminScriptLabLineController;
use App\Http\Controllers\Api\Admin\TestAdminPronunciationController;
use App\Http\Controllers\Api\Admin\UpdateAdminCoursePipelineController;
use App\Http\Controllers\Api\Admin\UpdateAdminPronunciationDictionaryController;
use App\Http\Controllers\Api\Admin\UploadAdminSpeakerAvatarController;
use App\Http\Controllers\Api\Admin\UploadAdminUserAvatarController;
use Illuminate\Support\Facades\Route;

return static function (): void {
    Route::get('/convolab/admin/stats', ShowAdminStatsController::class);
    Route::get('/convolab/admin/users', ListAdminUsersController::class);
    Route::delete('/convolab/admin/users/{convoLabUserId}', DeleteAdminUserController::class)
        ->whereUuid('convoLabUserId')
        ->middleware('throttle:'.AdminMutationRateLimiter::USER_DELETE);
    Route::get('/convolab/admin/users/{convoLabUserId}/info', ShowAdminUserController::class)
        ->whereUuid('convoLabUserId');
    Route::get('/convolab/admin/invite-codes', ListAdminInviteCodesController::class);
    Route::post('/convolab/admin/invite-codes', CreateAdminInviteCodeController::class)
        ->middleware('throttle:'.AdminMutationRateLimiter::INVITE_CREATE);
    Route::delete('/convolab/admin/invite-codes/{inviteId}', DeleteAdminInviteCodeController::class)
        ->whereUuid('inviteId')
        ->middleware('throttle:'.AdminMutationRateLimiter::INVITE_DELETE);
    Route::get(
        '/convolab/admin/avatars/speaker/{filename}/original',
        ShowAdminSpeakerAvatarOriginalController::class,
    );
    Route::post(
        '/convolab/admin/avatars/speaker/{filename}/upload',
        UploadAdminSpeakerAvatarController::class,
    )->middleware('throttle:'.AdminMutationRateLimiter::SPEAKER_AVATAR_UPLOAD);
    Route::post(
        '/convolab/admin/avatars/speaker/{filename}/recrop',
        RecropAdminSpeakerAvatarController::class,
    )->middleware('throttle:'.AdminMutationRateLimiter::SPEAKER_AVATAR_RECROP);
    Route::post(
        '/convolab/admin/avatars/user/{convoLabUserId}/upload',
        UploadAdminUserAvatarController::class,
    )->whereUuid('convoLabUserId')
        ->middleware('throttle:'.AdminMutationRateLimiter::USER_AVATAR_UPLOAD);
    Route::get('/convolab/admin/avatars/speakers', ListAdminSpeakerAvatarsController::class);
    Route::get(
        '/convolab/admin/pronunciation-dictionaries',
        ShowAdminPronunciationDictionaryController::class,
    );
    Route::put(
        '/convolab/admin/pronunciation-dictionaries',
        UpdateAdminPronunciationDictionaryController::class,
    )->middleware('throttle:'.AdminMutationRateLimiter::PRONUNCIATION_DICTIONARY_UPDATE);
    Route::get('/convolab/admin/script-lab/courses', ListAdminScriptLabCoursesController::class);
    Route::post('/convolab/admin/script-lab/courses', StoreAdminScriptLabCourseController::class)
        ->middleware('throttle:'.AdminMutationRateLimiter::SCRIPT_LAB_COURSE_CREATE);
    Route::get(
        '/convolab/admin/script-lab/courses/{courseId}',
        ShowAdminScriptLabCourseController::class,
    )->whereUuid('courseId');
    Route::delete(
        '/convolab/admin/script-lab/courses',
        DeleteAdminScriptLabCoursesController::class,
    )->middleware('throttle:'.AdminMutationRateLimiter::SCRIPT_LAB_COURSE_DELETE);
    Route::post(
        '/convolab/admin/script-lab/sentence-script',
        GenerateAdminSentenceScriptController::class,
    )->middleware('throttle:'.AdminMutationRateLimiter::SENTENCE_SCRIPT_GENERATE);
    Route::get(
        '/convolab/admin/script-lab/sentence-tests',
        ListAdminSentenceScriptTestsController::class,
    );
    Route::get(
        '/convolab/admin/script-lab/sentence-tests/{testId}',
        ShowAdminSentenceScriptTestController::class,
    )->whereUuid('testId');
    Route::delete(
        '/convolab/admin/script-lab/sentence-tests',
        DeleteAdminSentenceScriptTestsController::class,
    )->middleware('throttle:'.AdminMutationRateLimiter::SENTENCE_SCRIPT_DELETE);
    Route::post(
        '/convolab/admin/script-lab/synthesize-line',
        SynthesizeAdminScriptLabLineController::class,
    )->middleware('throttle:'.AdminMutationRateLimiter::SCRIPT_LAB_LINE_SYNTHESIZE);
    Route::post(
        '/convolab/admin/script-lab/test-pronunciation',
        TestAdminPronunciationController::class,
    )->middleware('throttle:'.AdminMutationRateLimiter::SCRIPT_LAB_PRONUNCIATION_TEST);
    Route::get(
        '/convolab/admin/script-lab/audio/{renderingId}',
        DownloadAdminScriptLabAudioController::class,
    )->whereUuid('renderingId');
    Route::get(
        '/convolab/admin/courses/{courseId}/pipeline-data',
        ShowAdminCoursePipelineController::class,
    )->whereUuid('courseId');
    Route::put(
        '/convolab/admin/courses/{courseId}/pipeline-data',
        UpdateAdminCoursePipelineController::class,
    )->whereUuid('courseId')
        ->middleware('throttle:'.AdminMutationRateLimiter::COURSE_PIPELINE_UPDATE);
    Route::post(
        '/convolab/admin/courses/{courseId}/build-script-config',
        BuildAdminCourseScriptConfigController::class,
    )->whereUuid('courseId');
    // Legacy uses POST, but prompt building is a local read with no provider spend.
    Route::post(
        '/convolab/admin/courses/{courseId}/build-prompt',
        BuildAdminCoursePromptController::class,
    )->whereUuid('courseId');
    Route::post(
        '/convolab/admin/courses/{courseId}/generate-dialogue',
        GenerateAdminCourseDialogueController::class,
    )->whereUuid('courseId')
        ->middleware('throttle:'.AdminMutationRateLimiter::COURSE_DIALOGUE_GENERATE);
    Route::post(
        '/convolab/admin/courses/{courseId}/generate-script',
        GenerateAdminCourseScriptController::class,
    )->whereUuid('courseId')
        ->middleware('throttle:'.AdminMutationRateLimiter::COURSE_SCRIPT_GENERATE);
    Route::post(
        '/convolab/admin/courses/{courseId}/generate-audio',
        GenerateAdminCourseAudioController::class,
    )->whereUuid('courseId')
        ->middleware('throttle:'.AdminMutationRateLimiter::COURSE_AUDIO_GENERATE);
    Route::post(
        '/convolab/admin/courses/{courseId}/synthesize-line',
        SynthesizeAdminCourseLineController::class,
    )->whereUuid('courseId')
        ->middleware('throttle:'.AdminMutationRateLimiter::COURSE_LINE_SYNTHESIZE);
    Route::get(
        '/convolab/admin/courses/{courseId}/line-renderings',
        ListAdminCourseLineRenderingsController::class,
    )->whereUuid('courseId');
    Route::get(
        '/convolab/admin/courses/{courseId}/line-renderings/{renderingId}/audio',
        DownloadAdminCourseLineRenderingController::class,
    )->whereUuid('courseId')->whereUuid('renderingId');
    Route::delete(
        '/convolab/admin/courses/{courseId}/line-renderings/{renderingId}',
        DeleteAdminCourseLineRenderingController::class,
    )->whereUuid('courseId')->whereUuid('renderingId')
        ->middleware('throttle:'.AdminMutationRateLimiter::COURSE_LINE_DELETE);
};
