<?php

use App\Domain\Courses\Support\CourseRateLimiter;
use App\Http\Controllers\Api\Courses\DeleteCourseController;
use App\Http\Controllers\Api\Courses\ListCoursesController;
use App\Http\Controllers\Api\Courses\ShowCourseController;
use App\Http\Controllers\Api\Courses\StoreCourseController;
use App\Http\Controllers\Api\Courses\UpdateCourseController;
use Illuminate\Support\Facades\Route;

return static function (): void {
    // Course creates, updates, and deletes below have separate buckets so create retries cannot starve destructive actions.
    Route::get('/courses', ListCoursesController::class);
    Route::post('/courses', StoreCourseController::class)
        ->middleware('throttle:'.CourseRateLimiter::CREATE_NAME);
    Route::get('/courses/{course}', ShowCourseController::class)->whereUlid('course');
    Route::put('/courses/{course}', UpdateCourseController::class)
        ->whereUlid('course')
        ->middleware('throttle:'.CourseRateLimiter::UPDATE_NAME);
    Route::delete('/courses/{course}', DeleteCourseController::class)
        ->whereUlid('course')
        ->middleware('throttle:'.CourseRateLimiter::DELETE_NAME);
};
