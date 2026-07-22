<?php

namespace App\Http\Requests\Admin;

use App\Domain\Admin\Data\AdminAvatarCropArea;
use App\Domain\Admin\Exceptions\AdminMutationException;
use Illuminate\Contracts\Validation\Validator;

class RecropAdminSpeakerAvatarRequest extends ConvoLabAdminWriteRequest
{
    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        $this->merge(['filename' => $this->route('filename')]);
    }

    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'filename' => ['required', 'string'],
            'cropArea' => ['present'],
        ]);
    }

    public function filename(): string
    {
        $data = $this->validated();

        return $data['filename'];
    }

    public function cropArea(): AdminAvatarCropArea
    {
        $data = $this->validated();

        return AdminAvatarCropArea::from($data['cropArea']);
    }

    protected function failedValidation(Validator $validator): never
    {
        if ($validator->errors()->has('cropArea')) {
            throw AdminMutationException::invalidAvatarCrop();
        }

        parent::failedValidation($validator);
    }
}
