<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

abstract class CursorPaginatedRequest extends FormRequest
{
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
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.$this->maxPerPage()],
        ];
    }

    public function perPage(): int
    {
        return $this->integer('per_page', $this->defaultPerPage());
    }

    protected function defaultPerPage(): int
    {
        return $this->maxPerPage();
    }

    abstract protected function maxPerPage(): int;
}
