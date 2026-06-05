<?php

namespace App\Http\Requests\Study;

use App\Domain\Study\Actions\ListStudyBrowserAction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListStudyBrowserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];

        foreach (['q', 'noteType', 'cursor', 'cardType', 'queueState', 'sortField', 'sortDirection'] as $key) {
            $value = $this->input($key);

            if (is_string($value)) {
                $normalized[$key] = trim($value);
            }
        }

        foreach (['cardType', 'queueState', 'sortField', 'sortDirection'] as $key) {
            if (isset($normalized[$key])) {
                $normalized[$key] = strtolower($normalized[$key]);
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
        return [
            'q' => ['sometimes', 'filled', 'string', 'max:200'],
            'noteType' => ['sometimes', 'filled', 'string', 'max:200'],
            'cardType' => ['sometimes', 'nullable', 'string', Rule::in(['recognition', 'production', 'cloze'])],
            'queueState' => ['sometimes', 'nullable', 'string', Rule::in(['new', 'learning', 'review', 'relearning', 'suspended', 'buried'])],
            'sortField' => ['sometimes', 'nullable', 'string', Rule::in(ListStudyBrowserAction::ALLOWED_SORT_FIELDS)],
            'sortDirection' => ['sometimes', 'nullable', 'string', Rule::in(ListStudyBrowserAction::ALLOWED_SORT_DIRECTIONS)],
            'cursor' => [
                'sometimes',
                'bail',
                'string',
                'max:1000',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (trim($value) === '') {
                        $fail('cursor must be a non-empty string.');

                        return;
                    }

                    try {
                        ListStudyBrowserAction::decodeCursorPayload($value);
                    } catch (\InvalidArgumentException) {
                        $fail('cursor is invalid.');
                    }
                },
            ],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:'.ListStudyBrowserAction::MAX_LIMIT],
        ];
    }

    public function searchQuery(): ?string
    {
        return $this->nullableString('q');
    }

    public function noteType(): ?string
    {
        return $this->nullableString('noteType');
    }

    public function cardType(): ?string
    {
        return $this->nullableString('cardType');
    }

    public function queueState(): ?string
    {
        return $this->nullableString('queueState');
    }

    public function sortField(): ?string
    {
        return $this->nullableString('sortField');
    }

    public function sortDirection(): ?string
    {
        return $this->nullableString('sortDirection');
    }

    public function cursor(): ?string
    {
        return $this->nullableString('cursor');
    }

    public function limit(): ?int
    {
        $validated = $this->validated();

        if (! array_key_exists('limit', $validated)) {
            return null;
        }

        return (int) $validated['limit'];
    }

    private function nullableString(string $key): ?string
    {
        $validated = $this->validated();

        if (! array_key_exists($key, $validated) || $validated[$key] === null || $validated[$key] === '') {
            return null;
        }

        return (string) $validated[$key];
    }
}
