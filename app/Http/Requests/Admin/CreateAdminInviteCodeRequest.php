<?php

namespace App\Http\Requests\Admin;

class CreateAdminInviteCodeRequest extends ConvoLabAdminWriteRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'customCode' => ['sometimes', 'nullable', 'string', 'regex:/^[A-Za-z0-9]{6,20}$/'],
        ]);
    }

    public function customCode(): ?string
    {
        $data = $this->validated();
        $code = $data['customCode'] ?? null;

        return is_string($code) ? $code : null;
    }
}
