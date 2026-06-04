<?php

namespace App\Policies;

use App\Domain\Courses\Models\Course;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class CoursePolicy
{
    public function view(User $user, Course $course): Response
    {
        return $course->user_id === $user->id
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
