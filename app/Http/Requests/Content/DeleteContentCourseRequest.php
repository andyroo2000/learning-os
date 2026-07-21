<?php

namespace App\Http\Requests\Content;

class DeleteContentCourseRequest extends ConvoLabContentWriteRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return $this->convoLabUserIdRules();
    }
}
