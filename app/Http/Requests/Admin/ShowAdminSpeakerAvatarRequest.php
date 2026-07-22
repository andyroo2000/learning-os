<?php

namespace App\Http\Requests\Admin;

class ShowAdminSpeakerAvatarRequest extends ConvoLabAdminReadRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['filename' => $this->route('filename')]);
    }

    public function rules(): array
    {
        return ['filename' => ['required', 'string']];
    }

    public function filename(): string
    {
        $data = $this->validated();

        return $data['filename'];
    }
}
