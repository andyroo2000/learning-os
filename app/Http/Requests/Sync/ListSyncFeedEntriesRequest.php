<?php

namespace App\Http\Requests\Sync;

use App\Domain\Sync\Enums\SyncFeedOperation;
use App\Domain\Sync\Models\SyncFeedEntry;
use App\Support\Pagination\CursorPageSize;
use App\Support\Pagination\CursorPagination;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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

        $resourceId = $this->input('resource_id');

        if (is_string($resourceId)) {
            $this->merge([
                'resource_id' => trim($resourceId),
            ]);
        }

        $operation = $this->input('operation');

        if (is_string($operation)) {
            $this->merge([
                'operation' => trim($operation),
            ]);
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
            'after_checkpoint' => ['sometimes', 'integer', 'min:0'],
            // resource_id is only unique inside its domain/type scope; keep those filters optional otherwise.
            'domain' => ['required_with:resource_id', 'filled', 'string', 'max:'.SyncFeedEntry::MAX_DOMAIN_LENGTH],
            'resource_type' => ['required_with:resource_id', 'filled', 'string', 'max:'.SyncFeedEntry::MAX_RESOURCE_TYPE_LENGTH],
            'resource_id' => ['sometimes', 'filled', 'string', 'max:'.SyncFeedEntry::MAX_RESOURCE_ID_LENGTH],
            'operation' => ['sometimes', 'filled', 'string', Rule::in(SyncFeedOperation::values())],
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

    public function resourceId(): ?string
    {
        if (! $this->has('resource_id')) {
            return null;
        }

        return (string) $this->input('resource_id');
    }

    public function operation(): ?string
    {
        if (! $this->has('operation')) {
            return null;
        }

        return (string) $this->input('operation');
    }
}
