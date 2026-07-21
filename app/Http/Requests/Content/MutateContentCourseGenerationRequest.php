<?php

namespace App\Http\Requests\Content;

class MutateContentCourseGenerationRequest extends ConvoLabContentWriteRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return $this->convoLabUserIdRules();
    }
}
