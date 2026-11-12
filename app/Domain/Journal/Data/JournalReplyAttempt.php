<?php

namespace App\Domain\Journal\Data;

use App\Support\Identifiers\CanonicalUlid;
use Illuminate\Support\Facades\Validator;

final readonly class JournalReplyAttempt
{
    public string $replyId;

    public string $token;

    public function __construct(string $replyId, string $token)
    {
        Validator::make(compact('replyId', 'token'), [
            'replyId' => ['required', 'ulid'], 'token' => ['required', 'ulid'],
        ])->validate();
        $this->replyId = CanonicalUlid::normalize($replyId);
        $this->token = CanonicalUlid::normalize($token);
    }
}
