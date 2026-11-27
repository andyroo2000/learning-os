<?php

use App\Domain\FeatureFlags\Support\AudienceInput;
use App\Http\Controllers\Api\FeatureFlags\FeatureAudienceController;
use Illuminate\Support\Facades\Route;

Route::prefix('/convolab/admin/feature-access')->group(function (): void {
    Route::get('/users', [FeatureAudienceController::class, 'search'])->middleware('throttle:120,1,feature-user-search');
    Route::get('/groups', [FeatureAudienceController::class, 'groups']);
    Route::post('/groups', [FeatureAudienceController::class, 'createGroup'])->middleware('throttle:30,1,feature-group-write');
    Route::put('/groups/{group}', [FeatureAudienceController::class, 'saveGroup'])
        ->whereUuid('group')->middleware('throttle:30,1,feature-group-write');
    Route::get('/audiences/{feature}', [FeatureAudienceController::class, 'show'])->whereIn('feature', AudienceInput::FEATURES);
    Route::put('/audiences/{feature}', [FeatureAudienceController::class, 'save'])
        ->whereIn('feature', AudienceInput::FEATURES)->middleware('throttle:30,1,feature-audience-write');
});
