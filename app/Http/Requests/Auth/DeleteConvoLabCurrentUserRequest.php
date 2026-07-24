<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\Auth\Concerns\NormalizesConvoLabUserId;
use App\Http\Support\ConvoLabRequestIdentity;
use Illuminate\Foundation\Http\FormRequest;

final class DeleteConvoLabCurrentUserRequest extends FormRequest
{
    use NormalizesConvoLabUserId;

    public function authorize(): bool
    {
        return ConvoLabRequestIdentity::allows($this);
    }

    public function rules(): array
    {
        return [
            'convolabUserId' => ['required', 'uuid'],
            'current_password' => ['required', 'string', 'max:1024'],
        ];
    }
}
