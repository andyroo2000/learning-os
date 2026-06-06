<?php

namespace App\Http\Requests\Study;

use App\Domain\Study\Actions\ListStudyCardDraftsAction;
use App\Domain\Study\Support\StudyCardDraftCursor;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use InvalidArgumentException;

class ListStudyCardDraftsRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $normalized = [];

        // Normalize here because some compatibility callers/tests do not rely on global TrimStrings middleware.
        foreach (['cursor', 'limit'] as $key) {
            $value = $this->input($key);

            if (is_string($value)) {
                $normalized[$key] = trim($value);
            }
        }

        if ($normalized !== []) {
            $this->merge($normalized);
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
            'cursor' => ['sometimes', 'filled', 'string', 'max:512'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:'.ListStudyCardDraftsAction::MAX_LIMIT],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $cursor = $this->validated('cursor');

                if (! is_string($cursor)) {
                    return;
                }

                try {
                    StudyCardDraftCursor::decode($cursor);
                } catch (InvalidArgumentException) {
                    $validator->errors()->add('cursor', 'The cursor is invalid.');
                }
            },
        ];
    }

    public function cursor(): ?string
    {
        return $this->validated('cursor');
    }

    public function limit(): int
    {
        return (int) ($this->validated()['limit'] ?? ListStudyCardDraftsAction::DEFAULT_LIMIT);
    }
}
