<?php

namespace App\Http\Requests\Admin;

use App\Domain\Admin\Data\AdminAvatarCropArea;
use App\Domain\Admin\Exceptions\AdminMutationException;
use App\Domain\Admin\Services\InterventionAdminAvatarImageProcessor;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\UploadedFile;

abstract class AdminAvatarUploadRequest extends ConvoLabAdminWriteRequest
{
    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        $cropArea = $this->input('cropArea');
        if (is_string($cropArea)) {
            $decoded = json_decode($cropArea, true);
            if (is_array($decoded)) {
                $this->merge(['cropArea' => $decoded]);
            }
        }
    }

    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'cropArea' => ['present'],
            'image' => [
                'required',
                'file',
                'max:'.(InterventionAdminAvatarImageProcessor::MAX_BYTES / 1024),
                'mimetypes:image/jpeg,image/png,image/webp',
            ],
        ]);
    }

    public function cropArea(): AdminAvatarCropArea
    {
        $data = $this->validated();

        return AdminAvatarCropArea::from($data['cropArea']);
    }

    public function imageBytes(): string
    {
        $data = $this->validated();
        $image = $data['image'];
        if (! $image instanceof UploadedFile) {
            throw AdminMutationException::invalidAvatarImage();
        }

        return $image->getContent();
    }

    protected function failedValidation(Validator $validator): never
    {
        if ($validator->errors()->has('actorConvoLabUserId')) {
            parent::failedValidation($validator);
        }
        if ($validator->errors()->has('image')) {
            if (! array_key_exists('image', $this->allFiles())) {
                throw AdminMutationException::missingAvatarImage();
            }

            throw AdminMutationException::invalidAvatarImage();
        }
        if ($validator->errors()->has('cropArea')) {
            throw AdminMutationException::invalidAvatarCrop();
        }

        parent::failedValidation($validator);
    }
}
