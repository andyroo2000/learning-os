<?php

namespace App\Http\Controllers\Api\Courses;

use App\Domain\Courses\Actions\ListCoursesAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Courses\ListCoursesRequest;
use App\Http\Resources\Courses\CourseResource;
use App\Models\User;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ListCoursesController extends Controller
{
    public function __invoke(ListCoursesRequest $request, ListCoursesAction $listCourses): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        return CourseResource::collection(
            $listCourses
                ->handle(
                    userId: $user->id,
                    pageSize: $request->pageSize(),
                    status: $request->status(),
                    nativeLanguage: $request->nativeLanguage(),
                    targetLanguage: $request->targetLanguage(),
                )
                ->withQueryString()
        );
    }
}
