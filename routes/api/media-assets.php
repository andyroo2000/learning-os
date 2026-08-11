<?php

use App\Domain\Media\Support\MediaAssetRateLimiter;
use App\Http\Controllers\Api\Media\DeleteMediaAssetController;
use App\Http\Controllers\Api\Media\DownloadMediaAssetContentController;
use App\Http\Controllers\Api\Media\ListMediaAssetsController;
use App\Http\Controllers\Api\Media\ShowMediaAssetController;
use App\Http\Controllers\Api\Media\StoreMediaAssetController;
use Illuminate\Support\Facades\Route;

return static function (): void {
    Route::get('/media-assets', ListMediaAssetsController::class);
    Route::post('/media-assets', StoreMediaAssetController::class)
        ->middleware('throttle:'.MediaAssetRateLimiter::CREATE_NAME);
    Route::get('/media-assets/{mediaAsset}/content', DownloadMediaAssetContentController::class)
        ->name('api.media-assets.content');
    Route::get('/media-assets/{mediaAsset}', ShowMediaAssetController::class);
    // Use a raw ID segment so missing/cross-user media assets stay idempotent 204s.
    Route::delete('/media-assets/{mediaAssetId}', DeleteMediaAssetController::class)
        ->middleware('throttle:'.MediaAssetRateLimiter::DELETE_NAME);
};
