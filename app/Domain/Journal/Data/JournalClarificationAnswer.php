<?php

namespace App\Domain\Journal\Data;

use App\Support\Identifiers\CanonicalUlid;
use Illuminate\Support\Facades\Validator;

final readonly class JournalClarificationAnswer
{
    public string $clarificationId;

    public string $questionId;

    public string $answer;

    public function __construct(string $clarificationId, string $questionId, string $answer)
    {
        $this->clarificationId = CanonicalUlid::normalize($clarificationId);
        $this->questionId = CanonicalUlid::normalize($questionId);
        $this->answer = trim($answer);
        Validator::make(['clarificationId' => $this->clarificationId, 'questionId' => $this->questionId, 'answer' => $this->answer], [
            'clarificationId' => ['required', 'ulid'], 'questionId' => ['required', 'ulid'],
            'answer' => ['required', 'string', 'max:2000', 'not_regex:/\x00/'],
        ])->validate();
    }
}
