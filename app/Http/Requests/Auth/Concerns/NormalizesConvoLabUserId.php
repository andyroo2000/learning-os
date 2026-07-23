<?php

namespace App\Http\Requests\Auth\Concerns;

use App\Http\Support\ConvoLabRequestIdentity;

trait NormalizesConvoLabUserId
{
    protected function prepareForValidation(): void
    {
        $this->prepareConvoLabUserIdForValidation();
    }

    protected function prepareConvoLabUserIdForValidation(): void
    {
        $this->merge([
            'convolabUserId' => ConvoLabRequestIdentity::userId($this),
        ]);
    }

    public function convoLabUserId(): string
    {
        return $this->validated('convolabUserId');
    }
}
