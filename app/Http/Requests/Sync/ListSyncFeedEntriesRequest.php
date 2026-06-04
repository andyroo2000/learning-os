<?php

namespace App\Http\Requests\Sync;

use App\Domain\Sync\Enums\SyncFeedOperation;
use App\Domain\Sync\Models\SyncFeedEntry;
use App\Domain\Sync\Values\SyncFeedMetadata;
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
                'domain' => SyncFeedMetadata::normalize($domain),
            ]);
        }

        $resourceType = $this->input('resource_type');

        if (is_string($resourceType)) {
            $this->merge([
                'resource_type' => SyncFeedMetadata::normalize($resourceType),
            ]);
        }

        $resourceId = $this->input('resource_id');

        if (is_string($resourceId)) {
            $this->merge([
                'resource_id' => SyncFeedMetadata::normalize($resourceId),
            ]);
        }

        $operation = $this->input('operation');

        if (is_string($operation)) {
            $this->merge([
                'operation' => SyncFeedMetadata::normalize($operation),
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
        return (int) ($this->validated()['after_checkpoint'] ?? 0);
    }

    public function domain(): ?string
    {
        $validated = $this->validated();

        if (! array_key_exists('domain', $validated)) {
            return null;
        }

        return $validated['domain'];
    }

    public function pageSize(): CursorPageSize
    {
        return CursorPageSize::fromPerPage(
            (int) ($this->validated()['per_page'] ?? CursorPagination::DEFAULT_PAGE_SIZE)
        );
    }

    public function resourceType(): ?string
    {
        $validated = $this->validated();

        if (! array_key_exists('resource_type', $validated)) {
            return null;
        }

        return $validated['resource_type'];
    }

    public function resourceId(): ?string
    {
        $validated = $this->validated();

        if (! array_key_exists('resource_id', $validated)) {
            return null;
        }

        return $validated['resource_id'];
    }

    public function operation(): ?string
    {
        $validated = $this->validated();

        if (! array_key_exists('operation', $validated)) {
            return null;
        }

        return $validated['operation'];
    }
}
