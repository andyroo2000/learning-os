<?php

use App\Http\Controllers\Api\Sync\ListSyncFeedEntriesController;
use Illuminate\Support\Facades\Route;

return static function (): void {
    Route::get('/sync/feed', ListSyncFeedEntriesController::class);
};
