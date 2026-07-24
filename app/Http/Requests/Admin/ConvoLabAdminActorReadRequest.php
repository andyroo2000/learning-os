<?php

namespace App\Http\Requests\Admin;

use App\Domain\Content\Support\ConvoLabUserId;
use App\Http\Support\ConvoLabRequestIdentity;

class ConvoLabAdminActorReadRequest extends ConvoLabAdminReadRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['actorConvoLabUserId' => ConvoLabRequestIdentity::userId($this)]);
    }

    public function rules(): array
    {
        return ['actorConvoLabUserId' => ['required', 'string', 'uuid']];
    }

    public function actorConvoLabUserId(): string
    {
        return ConvoLabUserId::normalize($this->validated('actorConvoLabUserId'));
    }
}
