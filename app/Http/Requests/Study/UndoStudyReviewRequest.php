<?php

namespace App\Http\Requests\Study;

use Illuminate\Foundation\Http\FormRequest;

class UndoStudyReviewRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $value = $this->input('timeZone');

        // Leave non-string values untouched so validation reports type errors instead of coercing them.
        if (is_string($value)) {
            $this->merge(['timeZone' => trim($value)]);
        }
    }

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'timeZone' => ['sometimes', 'nullable', 'string', 'timezone'],
            'currentOverview' => ['sometimes', 'nullable', 'array'],
        ];
    }

    public function timeZone(): ?string
    {
        $validated = $this->validated();

        if (! array_key_exists('timeZone', $validated)) {
            return null;
        }

        return $validated['timeZone'] === null ? null : (string) $validated['timeZone'];
    }
}
