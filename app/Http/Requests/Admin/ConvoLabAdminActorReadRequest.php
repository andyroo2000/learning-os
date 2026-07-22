<?php

namespace App\Http\Requests\Admin;

use App\Domain\Content\Support\ConvoLabUserId;

class ConvoLabAdminActorReadRequest extends ConvoLabAdminReadRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['actorConvoLabUserId' => $this->header('X-Convo-Lab-User-Id')]);
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
