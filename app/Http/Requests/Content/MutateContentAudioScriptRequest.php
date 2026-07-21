<?php

namespace App\Http\Requests\Content;

class MutateContentAudioScriptRequest extends ConvoLabContentWriteRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return $this->convoLabUserIdRules();
    }
}
