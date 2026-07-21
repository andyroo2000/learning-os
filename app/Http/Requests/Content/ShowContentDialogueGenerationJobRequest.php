<?php

namespace App\Http\Requests\Content;

class ShowContentDialogueGenerationJobRequest extends ConvoLabContentUserRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return $this->convoLabUserIdRules();
    }
}
