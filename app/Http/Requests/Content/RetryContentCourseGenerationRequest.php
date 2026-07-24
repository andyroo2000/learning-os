<?php

namespace App\Http\Requests\Content;

final class RetryContentCourseGenerationRequest extends MutateContentCourseGenerationRequest
{
    protected function blocksDemoMutation(): bool
    {
        return true;
    }
}
