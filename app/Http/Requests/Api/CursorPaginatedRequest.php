<?php

namespace App\Http\Requests\Api;

use App\Http\Requests\Concerns\NormalizesStringInputs;
use App\Support\Pagination\CursorPageSize;
use App\Support\Pagination\CursorPagination;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Pagination\Cursor;
use Illuminate\Validation\Validator;

abstract class CursorPaginatedRequest extends FormRequest
{
    use NormalizesStringInputs;

    protected function prepareForValidation(): void
    {
        $this->mergeNormalizedStringInputs(['cursor', 'per_page']);
    }

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'cursor' => ['sometimes', 'filled', 'string', 'max:512'],
            'per_page' => ['sometimes', 'filled', 'integer', 'min:'.CursorPagination::MIN_PAGE_SIZE, 'max:'.$this->maxPerPage()],
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

                $decodedCursor = Cursor::fromEncoded($cursor);
                $parameters = $decodedCursor?->toArray() ?? [];

                if (
                    $decodedCursor === null
                    || empty(array_diff_key($parameters, ['_pointsToNextItems' => null]))
                    || ! is_bool($parameters['_pointsToNextItems'] ?? null)
                    || ! $this->cursorHasRequiredParameters($parameters)
                ) {
                    $validator->errors()->add('cursor', 'The cursor is invalid.');
                }
            },
        ];
    }

    /**
     * Return Laravel cursor parameter names in the same shape emitted by the endpoint query ordering.
     *
     * Qualified order columns such as cards.due_at are encoded with the qualified key.
     * Extra cursor keys are allowed; this only rejects malformed tokens and missing ordered-column keys.
     * Cursors from endpoints with the same ordered-column shape intentionally pass this structural check.
     *
     * @return list<string>
     */
    abstract protected function cursorParameters(): array;

    /**
     * @param  array<string, mixed>  $parameters
     */
    private function cursorHasRequiredParameters(array $parameters): bool
    {
        foreach ($this->cursorParameters() as $parameter) {
            if (! array_key_exists($parameter, $parameters)) {
                return false;
            }
        }

        return true;
    }

    public function pageSize(): CursorPageSize
    {
        return CursorPageSize::fromPerPage($this->perPage());
    }

    /**
     * Validated request input that is normalized by CursorPageSize::fromPerPage().
     */
    protected function perPage(): int
    {
        return (int) ($this->validated()['per_page'] ?? $this->defaultPerPage());
    }

    protected function defaultPerPage(): int
    {
        // Endpoint-specific caps may be lower than the global default.
        return min(CursorPagination::DEFAULT_PAGE_SIZE, $this->maxPerPage());
    }

    /**
     * Override to use a resource-specific page size cap.
     */
    protected function maxPerPage(): int
    {
        return CursorPagination::MAX_PAGE_SIZE;
    }
}
