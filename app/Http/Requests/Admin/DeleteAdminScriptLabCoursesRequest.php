<?php

namespace App\Http\Requests\Admin;

final class DeleteAdminScriptLabCoursesRequest extends ConvoLabAdminWriteRequest
{
    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        if (is_array($this->input('courseIds'))) {
            $this->merge([
                'courseIds' => array_map(
                    static fn (mixed $id): mixed => is_string($id) ? strtolower(trim($id)) : $id,
                    $this->input('courseIds'),
                ),
            ]);
        }
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'courseIds' => ['required', 'array', 'min:1', 'max:100'],
            'courseIds.*' => ['required', 'string', 'uuid', 'distinct'],
        ];
    }

    /** @return list<string> */
    public function courseIds(): array
    {
        return $this->validated('courseIds');
    }
}
