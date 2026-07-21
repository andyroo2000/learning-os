<?php

namespace App\Http\Requests\Content;

final class GenerateContentAudioScriptRenderRequest extends ConvoLabContentWriteRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return $this->convoLabUserIdRules();
    }
}
