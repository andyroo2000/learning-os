<?php

use App\Domain\Achievements\Support\AchievementEvaluationRateLimiter;
use App\Domain\Study\Support\StudyCompatibilityTrafficRateLimiter;
use App\Http\Controllers\Api\Achievements\EvaluateAchievementProgressController;
use App\Http\Controllers\Api\Achievements\ListAchievementCatalogController;
use App\Http\Controllers\Api\Achievements\ShowAchievementProgressController;
use Illuminate\Support\Facades\Route;

return [
    'public' => static function (): void {
        Route::get('/achievements/catalog', ListAchievementCatalogController::class);
    },
    'authenticated' => static function (): void {
        Route::get('/achievements/progress', ShowAchievementProgressController::class)
            ->middleware([
                'auth:sanctum',
                'throttle:'.StudyCompatibilityTrafficRateLimiter::READ_NAME,
            ]);
        Route::post('/achievements/evaluate', EvaluateAchievementProgressController::class)
            ->middleware([
                'auth:sanctum',
                'throttle:'.AchievementEvaluationRateLimiter::NAME,
            ]);
    },
];
