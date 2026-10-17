<?php

use App\Domain\Admin\Support\AdminMutationRateLimiter;
use App\Http\Controllers\Api\Admin\CreateAdminInviteCodeController;
use App\Http\Controllers\Api\Admin\DeleteAdminInviteCodeController;
use App\Http\Controllers\Api\Admin\DeleteAdminUserController;
use App\Http\Controllers\Api\Admin\ListAdminInviteCodesController;
use App\Http\Controllers\Api\Admin\ListAdminSpeakerAvatarsController;
use App\Http\Controllers\Api\Admin\ListAdminUsersController;
use App\Http\Controllers\Api\Admin\RecropAdminSpeakerAvatarController;
use App\Http\Controllers\Api\Admin\ShowAdminPronunciationDictionaryController;
use App\Http\Controllers\Api\Admin\ShowAdminSpeakerAvatarOriginalController;
use App\Http\Controllers\Api\Admin\ShowAdminStatsController;
use App\Http\Controllers\Api\Admin\ShowAdminUserController;
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
};
