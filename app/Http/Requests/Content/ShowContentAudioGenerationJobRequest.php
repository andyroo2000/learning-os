<?php

namespace App\Http\Requests\Content;

final class ShowContentAudioGenerationJobRequest extends ConvoLabContentUserRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return $this->convoLabUserIdRules();
    }
}
