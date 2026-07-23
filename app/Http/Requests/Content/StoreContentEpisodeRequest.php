<?php

namespace App\Http\Requests\Content;

use App\Domain\Content\Support\ContentEpisodeInput;
use Illuminate\Validation\Rule;

class StoreContentEpisodeRequest extends ConvoLabContentWriteRequest
{
    protected function blocksDemoMutation(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            ...$this->convoLabUserIdRules(),
            'title' => ['required', 'string', 'max:255'],
            'sourceText' => ['required', 'string'],
            'targetLanguage' => ['required', 'string', Rule::in(['ja'])],
            'nativeLanguage' => ['required', 'string', Rule::in(['en'])],
            'audioSpeed' => ['sometimes', 'required', 'string', Rule::in(ContentEpisodeInput::AUDIO_SPEEDS)],
            'jlptLevel' => ['sometimes', 'nullable', 'string', Rule::in(ContentEpisodeInput::JLPT_LEVELS)],
            'autoGenerateAudio' => ['sometimes', 'required', 'boolean'],
        ];
    }
}
