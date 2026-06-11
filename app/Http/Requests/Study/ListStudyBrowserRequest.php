<?php

namespace App\Http\Requests\Study;

use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Flashcards\Enums\CardType;
use App\Domain\Study\Actions\ListStudyBrowserAction;
use App\Http\Requests\Concerns\NormalizesStringInputs;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListStudyBrowserRequest extends FormRequest
{
    use NormalizesStringInputs;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->mergeNormalizedStringInputs(
            ['q', 'noteType', 'cursor', 'cardType', 'queueState', 'sortField', 'sortDirection', 'limit'],
            ['cardType', 'queueState', 'sortField', 'sortDirection'],
        );
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'q' => ['sometimes', 'filled', 'string', 'max:200'],
            'noteType' => ['sometimes', 'filled', 'string', 'max:200'],
            'cardType' => ['sometimes', 'nullable', 'string', Rule::in(CardType::values())],
            'queueState' => ['sometimes', 'nullable', 'string', Rule::in(CardStudyStatus::values())],
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
            'limit' => ['sometimes', 'filled', 'integer', 'min:1', 'max:'.ListStudyBrowserAction::MAX_LIMIT],
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
