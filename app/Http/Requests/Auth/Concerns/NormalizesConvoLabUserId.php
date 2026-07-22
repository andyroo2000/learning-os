<?php

namespace App\Http\Requests\Auth\Concerns;

trait NormalizesConvoLabUserId
{
    protected function prepareForValidation(): void
    {
        $this->prepareConvoLabUserIdForValidation();
    }

    protected function prepareConvoLabUserIdForValidation(): void
    {
        $userId = $this->header('X-Convo-Lab-User-Id');

        $this->merge([
            'convolabUserId' => is_string($userId) ? strtolower(trim($userId)) : $userId,
        ]);
    }

    public function convoLabUserId(): string
    {
        return $this->validated('convolabUserId');
    }
}
