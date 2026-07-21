<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Concerns\NormalizesStringInputs;

class ListAdminUsersRequest extends ConvoLabAdminReadRequest
{
    use NormalizesStringInputs;

    protected function prepareForValidation(): void
    {
        $this->merge([
            'page' => $this->input('page', 1),
            'limit' => $this->input('limit', 50),
        ]);

        $this->mergeNormalizedStringInputs(['search'], blankToNull: ['search']);
    }

    public function rules(): array
    {
        return [
            'page' => ['required', 'integer', 'min:1', 'max:1000000'],
            'limit' => ['required', 'integer', 'min:1', 'max:100'],
            'search' => ['sometimes', 'nullable', 'string', 'max:200'],
        ];
    }

    public function page(): int
    {
        return (int) $this->validated('page');
    }

    public function limit(): int
    {
        return (int) $this->validated('limit');
    }

    public function search(): ?string
    {
        return $this->nullableString('search');
    }
}
