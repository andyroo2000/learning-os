<?php

namespace App\Http\Requests\Study;

use App\Domain\Study\Actions\ListStudyCardDraftsAction;
use App\Domain\Study\Support\StudyCardDraftCursor;
use App\Http\Requests\Concerns\NormalizesStringInputs;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use InvalidArgumentException;

class ListStudyCardDraftsRequest extends FormRequest
{
    use NormalizesStringInputs;

    protected function prepareForValidation(): void
    {
        // Compatibility callers may bypass the global TrimStrings middleware.
        $this->mergeNormalizedStringInputs(['cursor', 'limit']);
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
            'limit' => ['sometimes', 'filled', 'integer', 'min:1', 'max:'.ListStudyCardDraftsAction::MAX_LIMIT],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->has('cursor')) {
                    return;
                }

                // validated() throws when sibling fields have errors; decode only after cursor's own rules pass.
                $cursor = $this->input('cursor');

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
