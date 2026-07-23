<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

final class ClaimConvoLabBrowserGoogleInviteRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $inviteCode = $this->input('inviteCode');
        if (is_string($inviteCode)) {
            $this->merge(['inviteCode' => trim($inviteCode)]);
        }
    }

    public function authorize(): bool
    {
        return $this->hasSession() && $this->attributes->get('sanctum') === true;
    }

    public function rules(): array
    {
        return ['inviteCode' => ['required', 'string', 'max:20']];
    }
}
