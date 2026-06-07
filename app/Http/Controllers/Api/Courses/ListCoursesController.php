<?php

namespace App\Http\Controllers\Api\Courses;

use App\Domain\Courses\Actions\ListCoursesAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Courses\ListCoursesRequest;
use App\Http\Resources\Courses\CourseResource;
use App\Http\Support\AuthenticatedUser;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ListCoursesController extends Controller
{
    public function __invoke(ListCoursesRequest $request, ListCoursesAction $listCourses): AnonymousResourceCollection
    {
        $userId = AuthenticatedUser::id($request);

        return CourseResource::collection(
            $listCourses
                ->handle(
                    userId: $userId,
                    pageSize: $request->pageSize(),
                    status: $request->status(),
                    nativeLanguage: $request->nativeLanguage(),
                    targetLanguage: $request->targetLanguage(),
                )
                ->withQueryString()
        );
    }
}
