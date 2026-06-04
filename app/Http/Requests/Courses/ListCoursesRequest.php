<?php

namespace App\Http\Requests\Courses;

use App\Domain\Courses\Enums\CourseStatus;
use App\Http\Requests\Api\CursorPaginatedRequest;
use Illuminate\Validation\Rule;

class ListCoursesRequest extends CursorPaginatedRequest
{
    private const TRIMMED_INPUT_KEYS = ['status', 'native_language', 'target_language'];

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        $input = [];

        foreach (self::TRIMMED_INPUT_KEYS as $key) {
            $value = $this->input($key);

            if (is_string($value)) {
                $input[$key] = trim($value);
            }
        }

        if ($input !== []) {
            $this->merge($input);
        }
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
        if (! $this->has('status')) {
            return null;
        }

        return CourseStatus::from((string) $this->input('status'));
    }

    public function nativeLanguage(): ?string
    {
        if (! $this->has('native_language')) {
            return null;
        }

        return (string) $this->input('native_language');
    }

    public function targetLanguage(): ?string
    {
        if (! $this->has('target_language')) {
            return null;
        }

        return (string) $this->input('target_language');
    }
}
