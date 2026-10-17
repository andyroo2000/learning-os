<?php

namespace App\Http\Requests\Readings;

use App\Domain\Readings\Support\ReadingNarrator;

final class ReadingNarrationRequest extends ReadingRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('voiceId'))) {
            $this->merge(['voiceId' => ReadingNarrator::resolve($this->input('voiceId'))]);
        }
    }

    public function rules(): array
    {
        return [...parent::rules(),
            'voiceId' => [$this->isMethod('PATCH') ? 'required' : 'sometimes', 'string', 'regex:/^fishaudio:[a-f0-9]{32}$/'],
            'expectedRevision' => [$this->isMethod('PATCH') ? 'required' : 'sometimes', 'integer', 'min:1']];
    }
}
