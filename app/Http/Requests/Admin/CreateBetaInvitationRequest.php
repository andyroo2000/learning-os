<?php

namespace App\Http\Requests\Admin;

final class CreateBetaInvitationRequest extends ConvoLabAdminWriteRequest
{
    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();
        $email = $this->input('email');
        if (is_string($email)) {
            $this->merge(['email' => trim($email)]);
        }
    }

    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'email' => ['required', 'string', 'email:rfc,strict', 'max:254'],
        ]);
    }
}
