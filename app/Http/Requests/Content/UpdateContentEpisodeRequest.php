<?php

namespace App\Http\Requests\Content;

class UpdateContentEpisodeRequest extends ConvoLabContentWriteRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'status' => ['sometimes', 'required', 'string', 'max:32'],
        ];
    }
}
