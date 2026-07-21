<?php

namespace App\Http\Requests\Content;

use App\Domain\Content\Support\ContentAudioScriptInput;
use Closure;

class StoreContentAudioScriptRequest extends ConvoLabContentWriteRequest
{
    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        $sourceText = $this->input('sourceText');
        $voiceId = $this->input('voiceId');
        $this->merge([
            'sourceText' => is_string($sourceText) ? trim($sourceText) : $sourceText,
            'voiceId' => is_string($voiceId) ? trim($voiceId) : $voiceId,
        ]);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            ...$this->convoLabUserIdRules(),
            'sourceText' => [
                'required',
                'string',
                'max:'.ContentAudioScriptInput::MAX_SOURCE_CHARACTERS,
                static function (string $attribute, mixed $value, Closure $fail): void {
                    if (is_string($value) && ! ContentAudioScriptInput::containsJapanese($value)) {
                        $fail('Script text must include Japanese.');
                    }
                },
            ],
            'voiceId' => ['sometimes', 'nullable', 'string', 'in:'.implode(',', ContentAudioScriptInput::VOICE_IDS)],
        ];
    }
}
