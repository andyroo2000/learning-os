<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\Auth\Concerns\NormalizesConvoLabUserId;
use App\Http\Support\ConvoLabProxyAuthorization;
use Illuminate\Foundation\Http\FormRequest;

final class ShowConvoLabGenerationQuotaRequest extends FormRequest
{
    use NormalizesConvoLabUserId;

    public function authorize(): bool
    {
        return ConvoLabProxyAuthorization::allows($this, 'auth:read');
    }

    public function rules(): array
    {
        return ['convolabUserId' => ['required', 'uuid']];
    }
}
