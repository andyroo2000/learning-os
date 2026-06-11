<?php

namespace App\Http\Requests\Courses;

use App\Domain\Courses\Enums\CourseStatus;
use App\Domain\Courses\Support\CourseLanguage;
use App\Http\Requests\Api\CursorPaginatedRequest;
use Illuminate\Validation\Rule;

class ListCoursesRequest extends CursorPaginatedRequest
{
    private const NORMALIZED_LANGUAGE_INPUT_KEYS = ['native_language', 'target_language'];

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();
        $this->mergeNormalizedStringInputs(['status'], ['status']);

        $this->mergeStringInputsUsing(
            self::NORMALIZED_LANGUAGE_INPUT_KEYS,
            fn (string $value, string $_key): string => CourseLanguage::normalize($value),
        );
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'status' => ['sometimes', 'filled', 'string', Rule::in(CourseStatus::values())],
            'native_language' => ['sometimes', 'filled', 'string', 'max:16'],
            'target_language' => ['sometimes', 'filled', 'string', 'max:16'],
        ];
    }

    public function status(): ?CourseStatus
    {
        $validated = $this->validated();

        if (! array_key_exists('status', $validated)) {
            return null;
        }

        return CourseStatus::from($validated['status']);
    }

    public function nativeLanguage(): ?string
    {
        $validated = $this->validated();

        if (! array_key_exists('native_language', $validated)) {
            return null;
        }

        return $validated['native_language'];
    }

    public function targetLanguage(): ?string
    {
        $validated = $this->validated();

        if (! array_key_exists('target_language', $validated)) {
            return null;
        }

        return $validated['target_language'];
    }

    protected function cursorParameters(): array
    {
        return ['updated_at', 'id'];
    }
}
