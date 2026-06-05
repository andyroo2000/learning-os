<?php

namespace App\Http\Requests\Flashcards;

use App\Http\Requests\Api\CursorPaginatedRequest;
use App\Http\Requests\Concerns\NormalizesUlidInput;

class ListDecksRequest extends CursorPaginatedRequest
{
    use NormalizesUlidInput;

    protected function prepareForValidation(): void
    {
        $normalized = [];

        $this->mergeNormalizedUlidInput($normalized, 'course_id');

        if ($normalized !== []) {
            $this->merge($normalized);
        }
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return parent::rules() + [
            'course_id' => ['sometimes', 'filled', 'ulid'],
        ];
    }

    public function courseId(): ?string
    {
        $validated = $this->validated();

        if (! array_key_exists('course_id', $validated)) {
            return null;
        }

        return $validated['course_id'];
    }
}
