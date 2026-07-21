<?php

namespace App\Http\Requests\Admin;

class ListAdminInviteCodesRequest extends ConvoLabAdminReadRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'page' => $this->input('page', 1),
            'limit' => $this->input('limit', 100),
        ]);
    }

    public function rules(): array
    {
        return [
            'page' => ['required', 'integer', 'min:1', 'max:1000000'],
            'limit' => ['required', 'integer', 'min:1', 'max:100'],
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
}
