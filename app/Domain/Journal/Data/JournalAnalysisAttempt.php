<?php

namespace App\Domain\Journal\Data;

use App\Support\Identifiers\CanonicalUlid;
use Illuminate\Support\Facades\Validator;

final readonly class JournalAnalysisAttempt
{
    public string $analysisId;

    public string $token;

    public function __construct(string $analysisId, string $token)
    {
        Validator::make(compact('analysisId', 'token'), [
            'analysisId' => ['required', 'ulid'], 'token' => ['required', 'ulid'],
        ])->validate();
        $this->analysisId = CanonicalUlid::normalize($analysisId);
        $this->token = CanonicalUlid::normalize($token);
    }
}
