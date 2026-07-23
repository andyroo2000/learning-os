<?php

namespace App\Http\Requests\Content;

class DeleteContentEpisodeRequest extends ConvoLabContentWriteRequest
{
    protected function blocksDemoMutation(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return $this->convoLabUserIdRules();
    }
}
