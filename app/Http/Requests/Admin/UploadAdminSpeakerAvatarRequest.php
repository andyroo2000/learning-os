<?php

namespace App\Http\Requests\Admin;

class UploadAdminSpeakerAvatarRequest extends AdminAvatarUploadRequest
{
    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        $this->merge(['filename' => $this->route('filename')]);
    }

    public function rules(): array
    {
        return array_merge(parent::rules(), ['filename' => ['required', 'string']]);
    }

    public function filename(): string
    {
        $data = $this->validated();

        return $data['filename'];
    }
}
