<?php

namespace App\Http\Requests\Flashcards\Concerns;

use App\Domain\Vocabulary\Enums\VocabVariantKind;
use App\Domain\Vocabulary\Enums\VocabVariantStatus;
use App\Domain\Vocabulary\Support\VocabVariantMetadataInput;
use DateTimeInterface;
use Illuminate\Validation\Rule;
use LogicException;

trait ValidatesCardVariantMetadata
{
    /**
     * @param  array<string, mixed>  $normalized
     */
    protected function mergeNormalizedCardVariantMetadataForValidation(array &$normalized): void
    {
        foreach (['variant_kind', 'variant_status'] as $key) {
            if (! array_key_exists($key, $this->all())) {
                continue;
            }

            $value = $this->input($key);

            if (is_string($value)) {
                $trimmed = trim($value);
                $normalized[$key] = $trimmed === '' ? null : strtolower($trimmed);
            }
        }

        if (array_key_exists('variant_stage', $this->all())) {
            $value = $this->input('variant_stage');

            if (is_string($value)) {
                $trimmed = trim($value);
                $integer = filter_var($trimmed, FILTER_VALIDATE_INT);

                if ($trimmed === '') {
                    $normalized['variant_stage'] = null;
                } elseif ($integer !== false) {
                    $normalized['variant_stage'] = $integer;
                }
            }
        }

        foreach (['variant_group_id', 'variant_sentence_id', 'variant_unlocked_at'] as $key) {
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
    protected function cardVariantMetadataRules(): array
    {
        return [
            'variant_group_id' => ['sometimes', 'nullable', 'string', 'max:'.VocabVariantMetadataInput::MAX_ID_LENGTH],
            'variant_sentence_id' => ['sometimes', 'nullable', 'string', 'max:'.VocabVariantMetadataInput::MAX_ID_LENGTH],
            'variant_kind' => ['sometimes', 'nullable', 'string', Rule::in(VocabVariantKind::values())],
            'variant_stage' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:'.VocabVariantMetadataInput::MAX_STAGE],
            'variant_status' => ['sometimes', 'nullable', 'string', Rule::in(VocabVariantStatus::values())],
            'variant_unlocked_at' => [
                'sometimes',
                'nullable',
                'string',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! is_string($value) || VocabVariantMetadataInput::canonicalUnlockedAtTimestamp($value) === null) {
                        $fail('variant_unlocked_at must be a valid timestamp.');
                    }
                },
            ],
        ];
    }

    public function hasVariantGroupId(): bool
    {
        return array_key_exists('variant_group_id', $this->validated());
    }

    public function variantGroupId(): ?string
    {
        return $this->nullableValidatedCardVariantString('variant_group_id');
    }

    public function hasVariantSentenceId(): bool
    {
        return array_key_exists('variant_sentence_id', $this->validated());
    }

    public function variantSentenceId(): ?string
    {
        return $this->nullableValidatedCardVariantString('variant_sentence_id');
    }

    public function hasVariantKind(): bool
    {
        return array_key_exists('variant_kind', $this->validated());
    }

    public function variantKind(): ?VocabVariantKind
    {
        $value = $this->nullableValidatedCardVariantString('variant_kind');

        return $value === null ? null : VocabVariantKind::from($value);
    }

    public function hasVariantStage(): bool
    {
        return array_key_exists('variant_stage', $this->validated());
    }

    public function variantStage(): ?int
    {
        $value = $this->validated('variant_stage');

        if ($value === null) {
            return null;
        }

        if (! is_int($value)) {
            throw new LogicException('variant_stage called after validation failed to reject a non-integer value.');
        }

        return $value;
    }

    public function hasVariantStatus(): bool
    {
        return array_key_exists('variant_status', $this->validated());
    }

    public function variantStatus(): ?VocabVariantStatus
    {
        $value = $this->nullableValidatedCardVariantString('variant_status');

        return $value === null ? null : VocabVariantStatus::from($value);
    }

    public function hasVariantUnlockedAt(): bool
    {
        return array_key_exists('variant_unlocked_at', $this->validated());
    }

    public function variantUnlockedAt(): ?DateTimeInterface
    {
        $value = $this->validated('variant_unlocked_at');

        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw new LogicException('variant_unlocked_at called after validation failed to reject a non-string value.');
        }

        $timestamp = VocabVariantMetadataInput::canonicalUnlockedAtTimestamp($value);

        if ($timestamp === null) {
            throw new LogicException('variant_unlocked_at called after validation failed to reject an invalid timestamp.');
        }

        return $timestamp;
    }

    private function nullableValidatedCardVariantString(string $key): ?string
    {
        $value = $this->validated($key);

        if ($value !== null && ! is_string($value)) {
            throw new LogicException("{$key} called after validation failed to reject a non-string value.");
        }

        return $value;
    }
}
