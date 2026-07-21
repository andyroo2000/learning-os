<?php

namespace App\Http\Requests\Content;

use App\Domain\Content\Support\ContentAudioScriptInput;
use Closure;

class UpdateContentAudioScriptSegmentsRequest extends ConvoLabContentWriteRequest
{
    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        $segments = $this->input('segments');
        if (is_array($segments)) {
            $segments = array_map(function (mixed $segment): mixed {
                if (! is_array($segment)) {
                    return $segment;
                }

                foreach (['text', 'reading', 'translation', 'imagePrompt'] as $key) {
                    if (is_string($segment[$key] ?? null)) {
                        $segment[$key] = trim($segment[$key]);
                    }
                }

                return $segment;
            }, $segments);
        }

        $normalized = ['segments' => $segments];
        foreach (['title', 'voiceId'] as $key) {
            if ($this->exists($key)) {
                $value = $this->input($key);
                $normalized[$key] = is_string($value) ? trim($value) : $value;
            }
        }
        $this->merge($normalized);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            ...$this->convoLabUserIdRules(),
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'voiceId' => ['sometimes', 'required', 'string', 'in:'.implode(',', ContentAudioScriptInput::VOICE_IDS)],
            'segments' => ['required', 'array', 'list', 'max:'.ContentAudioScriptInput::MAX_SEGMENTS],
            'segments.*' => ['required', 'array:text,reading,translation,imagePrompt'],
            'segments.*.text' => [
                'required',
                'string',
                'max:2000',
                static function (string $attribute, mixed $value, Closure $fail): void {
                    if (is_string($value) && ! ContentAudioScriptInput::containsJapanese($value)) {
                        $fail('Script segment text must include Japanese.');
                    }
                },
            ],
            'segments.*.reading' => ['sometimes', 'nullable', 'string', 'max:4000'],
            'segments.*.translation' => ['required', 'string', 'max:4000'],
            'segments.*.imagePrompt' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}
