<?php

namespace App\Http\Requests\Courses;

use App\Domain\Courses\Models\Course;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class UpdateCourseRequest extends FormRequest
{
    private const TRIMMED_INPUT_KEYS = ['title', 'description'];

    public function authorize(): bool
    {
        /** @var Course $course */
        $course = $this->route('course');

        // Throw via Gate so CoursePolicy's 404 denial is preserved; returning false here would become a 403.
        Gate::authorize('update', $course);

        return true;
    }

    protected function prepareForValidation(): void
    {
        $input = [];

        foreach (self::TRIMMED_INPUT_KEYS as $key) {
            if ($this->exists($key)) {
                $input[$key] = $this->trimStringInput($key);
            }
        }

        $this->merge($input);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // Complete mutable payload for now; status and language pair stay server/domain controlled.
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['present', 'nullable', 'string', 'max:'.StoreCourseRequest::DESCRIPTION_MAX_LENGTH],
        ];
    }

    private function trimStringInput(string $key): mixed
    {
        $value = $this->input($key);

        return is_string($value) ? trim($value) : $value;
    }
}
