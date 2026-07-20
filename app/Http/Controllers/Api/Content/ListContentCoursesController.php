<?php

namespace App\Http\Controllers\Api\Content;

use App\Domain\Content\Actions\ListContentCoursesAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Content\ListContentCoursesRequest;
use App\Http\Resources\Content\ContentCourseLibraryResource;
use App\Http\Resources\Content\ContentCourseResource;
use Illuminate\Http\JsonResponse;

class ListContentCoursesController extends Controller
{
    public function __invoke(ListContentCoursesRequest $request, ListContentCoursesAction $action): JsonResponse
    {
        $courses = $action->handle(
            $request->user()->getKey(),
            $request->limit(),
            $request->offset(),
            $request->library(),
            $request->status(),
        );
        $resources = $request->library()
            ? ContentCourseLibraryResource::collection($courses)
            : ContentCourseResource::collection($courses);

        return response()->json($resources->resolve($request));
    }
}
