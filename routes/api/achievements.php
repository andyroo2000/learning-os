<?php

use App\Http\Controllers\Api\Achievements\ListAchievementCatalogController;
use Illuminate\Support\Facades\Route;

return static function (): void {
    Route::get('/achievements/catalog', ListAchievementCatalogController::class);
};
