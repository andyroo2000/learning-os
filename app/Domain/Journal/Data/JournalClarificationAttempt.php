<?php

namespace App\Domain\Journal\Data;

use App\Support\Identifiers\CanonicalUlid;
use Illuminate\Support\Facades\Validator;

final readonly class JournalClarificationAttempt
{
    public string $id;

    public string $token;

    public function __construct(string $id, string $token, public int $revision)
    {
        $this->id = CanonicalUlid::normalize($id);
        $this->token = CanonicalUlid::normalize($token);
        Validator::make(['id' => $this->id, 'token' => $this->token, 'revision' => $revision], [
            'id' => ['required', 'ulid'], 'token' => ['required', 'ulid'],
            'revision' => ['integer', 'min:0', 'max:2147483647'],
        ])->validate();
    }
}
