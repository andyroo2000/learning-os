<?php

namespace App\Http\Requests\Admin;

use App\Domain\Content\Support\ConvoLabUserId;
use App\Http\Support\ConvoLabAdminAuthorization;
use App\Http\Support\ConvoLabRequestIdentity;
use Illuminate\Foundation\Http\FormRequest;

class ConvoLabAdminWriteRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['actorConvoLabUserId' => ConvoLabRequestIdentity::userId($this)]);
    }

    public function authorize(): bool
    {
        return ConvoLabAdminAuthorization::allows($this, 'admin:write');
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
