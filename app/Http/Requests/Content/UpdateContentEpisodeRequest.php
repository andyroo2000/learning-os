<?php

namespace App\Http\Requests\Content;

use App\Domain\Content\Support\ContentEpisodeInput;
use Illuminate\Validation\Rule;

class UpdateContentEpisodeRequest extends ConvoLabContentWriteRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            ...$this->convoLabUserIdRules(),
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'status' => ['sometimes', 'required', 'string', Rule::in(ContentEpisodeInput::STATUSES)],
        ];
    }
}
