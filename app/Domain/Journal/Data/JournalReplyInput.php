<?php

namespace App\Domain\Journal\Data;

use App\Support\Identifiers\CanonicalUlid;
use Illuminate\Support\Facades\Validator;

final readonly class JournalReplyInput
{
    public string $id;

    public string $analysisId;

    public string $answer;

    public int $expectedRevision;

    public bool $replace;

    public function __construct(array $input)
    {
        $data = Validator::make($input, [
            'id' => ['required', 'ulid'], 'analysisId' => ['required', 'ulid'],
            'answer' => ['required', 'string', 'max:5000', 'regex:/\S/u', 'not_regex:/\x00/'],
            'expectedRevision' => ['required', 'integer', 'min:0', 'max:2147483646'],
            'replace' => ['required', 'boolean'],
        ])->validate();
        $this->id = CanonicalUlid::normalize($data['id']);
        $this->analysisId = CanonicalUlid::normalize($data['analysisId']);
        $this->answer = $data['answer'];
        $this->expectedRevision = (int) $data['expectedRevision'];
        $this->replace = (bool) $data['replace'];
    }
}
