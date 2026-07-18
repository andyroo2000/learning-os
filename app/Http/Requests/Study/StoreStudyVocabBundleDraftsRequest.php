<?php

namespace App\Http\Requests\Study;

use App\Domain\Study\Data\CreateStudyVocabBundleData;
use Illuminate\Foundation\Http\FormRequest;
use LogicException;

class StoreStudyVocabBundleDraftsRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $normalized = [];

        foreach (['targetWord', 'sourceSentence', 'context'] as $key) {
            if (! array_key_exists($key, $this->all())) {
                continue;
            }

            $value = $this->input($key);
            if (is_string($value)) {
                $trimmed = trim($value);
                $normalized[$key] = $key === 'targetWord' ? $trimmed : ($trimmed === '' ? null : $trimmed);
            }
        }

        if ($normalized !== []) {
            $this->merge($normalized);
        }
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'targetWord' => ['required', 'string', 'max:'.CreateStudyVocabBundleData::MAX_TARGET_WORD_LENGTH],
            'sourceSentence' => ['sometimes', 'nullable', 'string', 'max:'.CreateStudyVocabBundleData::MAX_SOURCE_SENTENCE_LENGTH],
            'context' => ['sometimes', 'nullable', 'string', 'max:'.CreateStudyVocabBundleData::MAX_CONTEXT_LENGTH],
            'includeLearnerContext' => ['sometimes', 'boolean'],
        ];
    }

    public function targetWord(): string
    {
        $value = $this->validated('targetWord');

        if (! is_string($value)) {
            throw new LogicException('targetWord called after validation failed to require a string.');
        }

        return $value;
    }

    public function sourceSentence(): ?string
    {
        return $this->nullableString('sourceSentence');
    }

    public function context(): ?string
    {
        return $this->nullableString('context');
    }

    public function includeLearnerContext(): bool
    {
        $value = $this->validated('includeLearnerContext');

        return match ($value) {
            null => true,
            true, 1, '1' => true,
            false, 0, '0' => false,
            default => throw new LogicException(
                'includeLearnerContext called after validation failed to require a boolean.',
            ),
        };
    }

    private function nullableString(string $key): ?string
    {
        $value = $this->validated($key);

        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw new LogicException("{$key} called after validation failed to reject a non-string value.");
        }

        return $value;
    }
}
