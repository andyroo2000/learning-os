<?php

namespace App\Http\Requests\Courses;

use App\Domain\Courses\Enums\CourseStatus;
use App\Http\Requests\Api\CursorPaginatedRequest;
use Illuminate\Validation\Rule;

class ListCoursesRequest extends CursorPaginatedRequest
{
    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        $status = $this->input('status');

        if (is_string($status)) {
            $this->merge([
                'status' => trim($status),
            ]);
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
        ];
    }

    public function status(): ?CourseStatus
    {
        if (! $this->has('status')) {
            return null;
        }

        return CourseStatus::from((string) $this->input('status'));
    }
}
