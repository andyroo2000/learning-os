<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\Auth\Concerns\NormalizesConvoLabUserId;
use App\Http\Support\ConvoLabProxyAuthorization;
use Illuminate\Foundation\Http\FormRequest;

final class DeleteConvoLabCurrentUserRequest extends FormRequest
{
    use NormalizesConvoLabUserId;

    public function authorize(): bool
    {
        return ConvoLabProxyAuthorization::allows($this, 'auth:write');
    }

    public function rules(): array
    {
        return [
            'convolabUserId' => ['required', 'uuid'],
            'current_password' => ['required', 'string', 'max:1024'],
        ];
    }
}
