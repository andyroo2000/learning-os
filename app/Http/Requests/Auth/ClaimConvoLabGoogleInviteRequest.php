<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\Auth\Concerns\NormalizesConvoLabUserId;
use App\Http\Support\ConvoLabProxyAuthorization;
use Illuminate\Foundation\Http\FormRequest;

final class ClaimConvoLabGoogleInviteRequest extends FormRequest
{
    use NormalizesConvoLabUserId;

    protected function prepareForValidation(): void
    {
        $this->prepareConvoLabUserIdForValidation();

        $inviteCode = $this->input('inviteCode');
        if (is_string($inviteCode)) {
            $this->merge(['inviteCode' => trim($inviteCode)]);
        }
    }

    public function authorize(): bool
    {
        return ConvoLabProxyAuthorization::allows($this, 'auth:oauth');
    }

    public function rules(): array
    {
        return [
            'convolabUserId' => ['required', 'uuid'],
            'inviteCode' => ['required', 'string', 'max:20'],
        ];
    }
}
