<?php

namespace App\Http\Requests\Study;

use Closure;
use Illuminate\Foundation\Http\FormRequest;

class SetManualKnownKanjiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'kanji' => [
                'required',
                'string',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! is_string($value) || preg_match('/^[\x{3400}-\x{4DBF}\x{4E00}-\x{9FFF}\x{F900}-\x{FAFF}]$/u', $value) !== 1) {
                        $fail('kanji must be exactly one kanji character.');
                    }
                },
            ],
            'known' => ['required', 'boolean'],
        ];
    }

    public function kanji(): string
    {
        return (string) $this->validated('kanji');
    }

    public function known(): bool
    {
        return (bool) $this->validated('known');
    }
}
