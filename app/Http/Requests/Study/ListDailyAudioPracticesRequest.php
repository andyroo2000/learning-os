<?php

namespace App\Http\Requests\Study;

use App\Domain\Study\Actions\ListDailyAudioPracticesAction;
use App\Domain\Study\Support\DailyAudioPracticeCursor;
use App\Http\Requests\Concerns\NormalizesStringInputs;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use InvalidArgumentException;

class ListDailyAudioPracticesRequest extends FormRequest
{
    use NormalizesStringInputs;

    protected function prepareForValidation(): void
    {
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
            'paginated' => ['sometimes', 'filled', 'boolean'],
            'cursor' => ['sometimes', 'filled', 'string', 'max:512'],
            'limit' => [
                'sometimes',
                'filled',
                'integer',
                'min:1',
                'max:'.ListDailyAudioPracticesAction::PAGE_SIZE_MAX,
            ],
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

                $cursor = $this->input('cursor');
                if (! is_string($cursor)) {
                    return;
                }

                try {
                    if (DailyAudioPracticeCursor::decodeLegacyOffset($cursor) === null) {
                        DailyAudioPracticeCursor::decode($cursor);
                    }
                } catch (InvalidArgumentException) {
                    $validator->errors()->add('cursor', 'The cursor is invalid.');
                }
            },
        ];
    }

    public function paginated(): bool
    {
        return (bool) ($this->validated()['paginated'] ?? false);
    }

    public function cursor(): ?string
    {
        return $this->validated('cursor');
    }

    public function limit(): int
    {
        return (int) ($this->validated()['limit'] ?? ListDailyAudioPracticesAction::RECENT_LIMIT);
    }
}
