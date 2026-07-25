<?php

namespace App\Http\Requests\Study;

use App\Domain\Study\Actions\BuildStudyMediaBatchAction;
use App\Support\Identifiers\CanonicalUlid;
use Illuminate\Foundation\Http\FormRequest;

class DownloadStudyMediaBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $ids = $this->input('ids');

        if (! is_array($ids)) {
            return;
        }

        $this->merge([
            'ids' => array_map(
                fn (mixed $id): mixed => is_string($id) ? CanonicalUlid::normalize($id) : $id,
                $ids,
            ),
        ]);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1', 'max:'.BuildStudyMediaBatchAction::MAX_ITEMS],
            'ids.*' => ['required', 'ulid', 'distinct'],
        ];
    }

    /**
     * @return list<string>
     */
    public function ids(): array
    {
        /** @var list<string> $ids */
        $ids = $this->validated('ids');

        return $ids;
    }
}
