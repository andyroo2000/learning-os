<?php

namespace App\Http\Requests\Study\Concerns;

use App\Domain\Vocabulary\Enums\VocabVariantKind;
use App\Domain\Vocabulary\Enums\VocabVariantStatus;
use App\Domain\Vocabulary\Support\VocabVariantMetadataInput;
use DateTimeInterface;
use Illuminate\Validation\Rule;
use LogicException;

trait ValidatesVocabVariantMetadata
{
    /**
     * @param  array<string, mixed>  $normalized
     */
    protected function mergeNormalizedVocabVariantMetadataForValidation(array &$normalized): void
    {
        foreach (['variantKind', 'variantStatus'] as $key) {
            if (! array_key_exists($key, $this->all())) {
                continue;
            }

            $value = $this->input($key);

            if (is_string($value)) {
                $trimmed = trim($value);
                $normalized[$key] = $trimmed === '' ? null : strtolower($trimmed);
            }
        }

        if (array_key_exists('variantStage', $this->all())) {
            $value = $this->input('variantStage');

            if (is_string($value)) {
                $trimmed = trim($value);
                // Laravel's integer rule accepts signed integer strings such as "+3"; normalize the same way.
                $integer = filter_var($trimmed, FILTER_VALIDATE_INT);

                if ($trimmed === '') {
                    $normalized['variantStage'] = null;
                } elseif ($integer !== false) {
                    $normalized['variantStage'] = $integer;
                }
            }
        }

        foreach (['variantGroupId', 'variantSentenceId', 'variantUnlockedAt'] as $key) {
            if (! array_key_exists($key, $this->all())) {
                continue;
            }

            $value = $this->input($key);

            if (is_string($value)) {
                $trimmed = trim($value);
                $normalized[$key] = $trimmed === '' ? null : $trimmed;
            }
        }
    }

    /**
     * @return array<string, list<mixed>>
     */
    protected function variantMetadataRules(): array
    {
        return [
            'variantGroupId' => ['sometimes', 'nullable', 'string', 'max:'.VocabVariantMetadataInput::MAX_ID_LENGTH],
            'variantSentenceId' => ['sometimes', 'nullable', 'string', 'max:'.VocabVariantMetadataInput::MAX_ID_LENGTH],
            'variantKind' => ['sometimes', 'nullable', 'string', Rule::in(VocabVariantKind::values())],
            'variantStage' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:'.VocabVariantMetadataInput::MAX_STAGE],
            'variantStatus' => ['sometimes', 'nullable', 'string', Rule::in(VocabVariantStatus::values())],
            'variantUnlockedAt' => [
                'sometimes',
                'nullable',
                'string',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! is_string($value) || VocabVariantMetadataInput::compatibilityUnlockedAtTimestamp($value) === null) {
                        $fail('variantUnlockedAt must be a valid timestamp.');
                    }
                },
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function variantMetadataMessages(): array
    {
        return [
            'variantGroupId.string' => 'variantGroupId must be a string.',
            'variantGroupId.max' => 'variantGroupId must be '.VocabVariantMetadataInput::MAX_ID_LENGTH.' characters or fewer.',
            'variantSentenceId.string' => 'variantSentenceId must be a string.',
            'variantSentenceId.max' => 'variantSentenceId must be '.VocabVariantMetadataInput::MAX_ID_LENGTH.' characters or fewer.',
            'variantKind.string' => 'variantKind must be a string.',
            'variantKind.in' => 'variantKind is not supported.',
            'variantStage.integer' => 'variantStage must be an integer.',
            'variantStage.min' => 'variantStage must be between 1 and '.VocabVariantMetadataInput::MAX_STAGE.'.',
            'variantStage.max' => 'variantStage must be between 1 and '.VocabVariantMetadataInput::MAX_STAGE.'.',
            'variantStatus.string' => 'variantStatus must be a string.',
            'variantStatus.in' => 'variantStatus is not supported.',
            'variantUnlockedAt.string' => 'variantUnlockedAt must be a string.',
        ];
    }

    public function hasVariantGroupId(): bool
    {
        return array_key_exists('variantGroupId', $this->validated());
    }

    public function variantGroupId(): ?string
    {
        return $this->nullableValidatedStudyStringValue('variantGroupId');
    }

    public function hasVariantSentenceId(): bool
    {
        return array_key_exists('variantSentenceId', $this->validated());
    }

    public function variantSentenceId(): ?string
    {
        return $this->nullableValidatedStudyStringValue('variantSentenceId');
    }

    public function hasVariantKind(): bool
    {
        return array_key_exists('variantKind', $this->validated());
    }

    public function variantKind(): ?VocabVariantKind
    {
        $value = $this->nullableValidatedStudyStringValue('variantKind');

        return $value === null ? null : VocabVariantKind::from($value);
    }

    public function hasVariantStage(): bool
    {
        return array_key_exists('variantStage', $this->validated());
    }

    public function variantStage(): ?int
    {
        $value = $this->validated('variantStage');

        if ($value === null) {
            return null;
        }

        if (! is_int($value)) {
            throw new LogicException('variantStage called after validation failed to reject a non-integer value.');
        }

        return $value;
    }

    public function hasVariantStatus(): bool
    {
        return array_key_exists('variantStatus', $this->validated());
    }

    public function variantStatus(): ?VocabVariantStatus
    {
        $value = $this->nullableValidatedStudyStringValue('variantStatus');

        return $value === null ? null : VocabVariantStatus::from($value);
    }

    public function hasVariantUnlockedAt(): bool
    {
        return array_key_exists('variantUnlockedAt', $this->validated());
    }

    public function variantUnlockedAt(): ?DateTimeInterface
    {
        $value = $this->validated('variantUnlockedAt');

        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw new LogicException('variantUnlockedAt called after validation failed to reject a non-string value.');
        }

        $timestamp = VocabVariantMetadataInput::compatibilityUnlockedAtTimestamp($value);

        if ($timestamp === null) {
            throw new LogicException('variantUnlockedAt called after validation failed to reject an invalid timestamp.');
        }

        return $timestamp;
    }

    protected function nullableValidatedStudyStringValue(string $key): ?string
    {
        $value = $this->validated($key);

        if ($value !== null && ! is_string($value)) {
            throw new LogicException("{$key} called after validation failed to reject a non-string value.");
        }

        return $value;
    }
}
