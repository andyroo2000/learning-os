<?php

namespace App\Http\Controllers\Api\Study;

use App\Domain\Study\Actions\ListStudyExportCoursesAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\Courses\CourseResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ListStudyExportCoursesController extends Controller
{
    public function __invoke(Request $request, ListStudyExportCoursesAction $listStudyExportCourses): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        return CourseResource::collection(
            $listStudyExportCourses->handle($user->id),
        );
    }
}
