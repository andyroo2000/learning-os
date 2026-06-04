<?php

namespace App\Http\Requests\Flashcards;

use App\Http\Requests\Api\CursorPaginatedRequest;
use App\Support\Identifiers\CanonicalUlid;

class ListDueCardsRequest extends CursorPaginatedRequest
{
    protected function prepareForValidation(): void
    {
        $courseId = $this->input('course_id');

        if (is_string($courseId)) {
            $this->merge([
                'course_id' => CanonicalUlid::normalize($courseId),
            ]);
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
