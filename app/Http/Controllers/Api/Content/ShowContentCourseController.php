<?php

namespace App\Http\Controllers\Api\Content;

use App\Domain\Content\Actions\ShowContentCourseAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\Content\ContentCourseResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShowContentCourseController extends Controller
{
    public function __invoke(Request $request, ShowContentCourseAction $action, string $courseId): JsonResponse
    {
        return response()->json(
            (new ContentCourseResource($action->handle($request->user()->getKey(), $courseId)))->resolve($request),
        )->header('Cache-Control', 'private, max-age=60');
    }
}
