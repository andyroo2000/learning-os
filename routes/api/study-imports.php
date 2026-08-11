<?php

use App\Domain\Study\Support\StudyCompatibilityTrafficRateLimiter;
use App\Domain\Study\Support\StudyImportRateLimiter;
use App\Http\Controllers\Api\Study\CancelStudyImportUploadController;
use App\Http\Controllers\Api\Study\CompleteStudyImportUploadController;
use App\Http\Controllers\Api\Study\ListStudyImportJobsController;
use App\Http\Controllers\Api\Study\ShowCurrentStudyImportJobController;
use App\Http\Controllers\Api\Study\ShowStudyImportJobController;
use App\Http\Controllers\Api\Study\ShowStudyImportReadinessController;
use App\Http\Controllers\Api\Study\StoreStudyImportController;
use App\Http\Controllers\Api\Study\UploadStudyImportFileController;
use Illuminate\Support\Facades\Route;

return static function (): void {
    Route::get('/study/imports', ListStudyImportJobsController::class)
        ->middleware('throttle:'.StudyCompatibilityTrafficRateLimiter::READ_NAME);
    // Import lifecycle writes use separate rate limits so upload retries do not starve cancel/complete.
    Route::post('/study/imports', StoreStudyImportController::class)
        ->middleware('throttle:'.StudyImportRateLimiter::CREATE_NAME);
    Route::get('/study/imports/readiness', ShowStudyImportReadinessController::class)
        ->middleware('throttle:'.StudyCompatibilityTrafficRateLimiter::READ_NAME);
    Route::get('/study/imports/current', ShowCurrentStudyImportJobController::class)
        ->middleware('throttle:'.StudyCompatibilityTrafficRateLimiter::READ_NAME);
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
        ->where('studyImportJobId', '(?:[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}|[0-9a-fA-F]{8}(?:-[0-9a-fA-F]{4}){3}-[0-9a-fA-F]{12})')
        ->middleware('throttle:'.StudyCompatibilityTrafficRateLimiter::READ_NAME);
};
