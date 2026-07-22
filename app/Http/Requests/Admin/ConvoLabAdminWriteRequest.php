<?php

namespace App\Http\Requests\Admin;

use App\Domain\Content\Support\ConvoLabUserId;
use App\Http\Support\ConvoLabProxyAuthorization;
use Illuminate\Foundation\Http\FormRequest;

class ConvoLabAdminWriteRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['actorConvoLabUserId' => $this->header('X-Convo-Lab-User-Id')]);
    }

    public function authorize(): bool
    {
        return ConvoLabProxyAuthorization::allows($this, 'admin:write');
    }

    public function rules(): array
    {
        return ['actorConvoLabUserId' => ['required', 'string', 'uuid']];
    }

    public function actorConvoLabUserId(): string
    {
        $data = $this->validated();

        return ConvoLabUserId::normalize($data['actorConvoLabUserId']);
    }
}
