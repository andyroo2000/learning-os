<?php

namespace App\Http\Requests\Api;

use App\Support\Pagination\CursorPageSize;
use App\Support\Pagination\CursorPagination;
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
            'per_page' => ['sometimes', 'integer', 'min:'.CursorPagination::MIN_PAGE_SIZE, 'max:'.$this->maxPerPage()],
        ];
    }

    public function pageSize(): CursorPageSize
    {
        return CursorPageSize::fromPerPage($this->perPage());
    }

    /**
     * Raw request input that is normalized by CursorPageSize::fromPerPage().
     */
    protected function perPage(): int
    {
        return $this->integer('per_page', $this->defaultPerPage());
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
