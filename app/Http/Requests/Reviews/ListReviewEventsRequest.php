<?php

namespace App\Http\Requests\Reviews;

use App\Http\Requests\Api\CursorPaginatedRequest;
use App\Support\Identifiers\CanonicalUlid;

class ListReviewEventsRequest extends CursorPaginatedRequest
{
    protected function prepareForValidation(): void
    {
        $normalized = [];

        foreach (['course_id', 'card_id'] as $key) {
            $value = $this->input($key);

            if (is_string($value)) {
                $normalized[$key] = CanonicalUlid::normalize($value);
            }
        }

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
            'card_id' => ['sometimes', 'filled', 'ulid'],
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

    public function cardId(): ?string
    {
        $validated = $this->validated();

        if (! array_key_exists('card_id', $validated)) {
            return null;
        }

        return $validated['card_id'];
    }
}
