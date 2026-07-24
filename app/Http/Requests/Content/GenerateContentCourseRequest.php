<?php

namespace App\Http\Requests\Content;

final class GenerateContentCourseRequest extends MutateContentCourseGenerationRequest
{
    protected function requiresVerifiedEmail(): bool
    {
        return true;
    }

    protected function blocksDemoMutation(): bool
    {
        return true;
    }
}
