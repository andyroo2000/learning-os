<?php

namespace App\Http\Requests\Content;

use App\Domain\Content\Data\GenerateContentAudioScriptData;
use Closure;

final class GenerateContentAudioScriptImagesRequest extends ConvoLabVerifiedGenerationRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            ...$this->convoLabUserIdRules(),
            'force' => [
                'sometimes',
                static function (string $attribute, mixed $value, Closure $fail): void {
                    if (! is_bool($value)) {
                        $fail('The force field must be true or false.');
                    }
                },
            ],
        ];
    }

    public function generationData(): GenerateContentAudioScriptData
    {
        return GenerateContentAudioScriptData::images([
            'force' => $this->validated('force', false),
        ]);
    }
}
