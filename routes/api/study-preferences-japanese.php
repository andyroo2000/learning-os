<?php

use App\Domain\Japanese\Support\JapaneseKnowledgeRateLimiter;
use App\Domain\Study\Support\StudyCompatibilityTrafficRateLimiter;
use App\Domain\Study\Support\StudySettingsUpdateRateLimiter;
use App\Http\Controllers\Api\Study\ConnectWaniKaniController;
use App\Http\Controllers\Api\Study\DisconnectWaniKaniController;
use App\Http\Controllers\Api\Study\SetManualKnownKanjiController;
use App\Http\Controllers\Api\Study\ShowKnownKanjiController;
use App\Http\Controllers\Api\Study\ShowStudySettingsController;
use App\Http\Controllers\Api\Study\SyncWaniKaniKanjiController;
use App\Http\Controllers\Api\Study\UpdateStudySettingsController;
use Illuminate\Support\Facades\Route;

return static function (): void {
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
};
