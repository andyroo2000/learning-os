<?php

namespace App\Http\Requests\Admin;

final class DeleteAdminSentenceScriptTestsRequest extends ConvoLabAdminWriteRequest
{
    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        $ids = $this->input('ids');
        if (is_array($ids)) {
            $this->merge(['ids' => array_map(
                fn (mixed $id): mixed => is_string($id) ? strtolower(trim($id)) : $id,
                $ids,
            )]);
        }
    }

    public function rules(): array
    {
        return [
            ...parent::rules(),
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['required', 'string', 'uuid', 'distinct'],
        ];
    }

    /** @return list<string> */
    public function testIds(): array
    {
        return array_values($this->validated('ids'));
    }
}
