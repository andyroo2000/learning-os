<?php

namespace App\Http\Requests\Sync;

use App\Domain\Sync\Models\SyncFeedEntry;
use App\Support\Pagination\CursorPageSize;
use App\Support\Pagination\CursorPagination;
use Illuminate\Foundation\Http\FormRequest;

class ListSyncFeedEntriesRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $domain = $this->input('domain');

        if (is_string($domain)) {
            $this->merge([
                'domain' => trim($domain),
            ]);
        }

        $resourceType = $this->input('resource_type');

        if (is_string($resourceType)) {
            $this->merge([
                'resource_type' => trim($resourceType),
            ]);
        }
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
            'after_checkpoint' => ['sometimes', 'integer', 'min:0'],
            'domain' => ['sometimes', 'filled', 'string', 'max:'.SyncFeedEntry::MAX_DOMAIN_LENGTH],
            'resource_type' => ['sometimes', 'filled', 'string', 'max:'.SyncFeedEntry::MAX_RESOURCE_TYPE_LENGTH],
            'per_page' => ['sometimes', 'integer', 'min:'.CursorPagination::MIN_PAGE_SIZE, 'max:'.CursorPagination::MAX_PAGE_SIZE],
        ];
    }

    public function afterCheckpoint(): int
    {
        return $this->integer('after_checkpoint', 0);
    }

    public function domain(): ?string
    {
        if (! $this->has('domain')) {
            return null;
        }

        return (string) $this->input('domain');
    }

    public function pageSize(): CursorPageSize
    {
        return CursorPageSize::fromPerPage(
            $this->integer('per_page', CursorPagination::DEFAULT_PAGE_SIZE)
        );
    }

    public function resourceType(): ?string
    {
        if (! $this->has('resource_type')) {
            return null;
        }

        return (string) $this->input('resource_type');
    }
}
