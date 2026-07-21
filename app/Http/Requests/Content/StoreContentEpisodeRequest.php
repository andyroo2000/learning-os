<?php

namespace App\Http\Requests\Content;

use Illuminate\Validation\Rule;

class StoreContentEpisodeRequest extends ConvoLabContentWriteRequest
{
    protected function prepareForValidation(): void
    {
        $convoLabUserId = $this->header('X-Convo-Lab-User-Id');

        $this->merge([
            'convolabUserId' => is_string($convoLabUserId)
                ? strtolower(trim($convoLabUserId))
                : $convoLabUserId,
        ]);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'convolabUserId' => ['required', 'uuid'],
            'title' => ['required', 'string', 'max:255'],
            'sourceText' => ['required', 'string'],
            'targetLanguage' => ['required', 'string', Rule::in(['ja'])],
            'nativeLanguage' => ['required', 'string', Rule::in(['en'])],
            'audioSpeed' => ['sometimes', 'required', 'string', 'max:32'],
            'jlptLevel' => ['sometimes', 'nullable', 'string', Rule::in(['N5', 'N4', 'N3', 'N2', 'N1'])],
            'autoGenerateAudio' => ['sometimes', 'required', 'boolean'],
        ];
    }
}
