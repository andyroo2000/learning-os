<?php

namespace App\Domain\Journal\Data;

use App\Support\Identifiers\CanonicalUlid;
use Illuminate\Support\Facades\Validator;

final readonly class JournalManualEditInput
{
    public string $analysisId;

    public int $revision;

    public string $text;

    public function __construct(array $input)
    {
        $data = Validator::make($input, [
            'analysisId' => ['required', 'ulid'],
            'expectedRevision' => ['required', 'integer', 'min:0', 'max:2147483646'],
            'text' => ['required', 'string', 'max:30000', 'not_regex:/\x00/', 'regex:/\S/u'],
        ])->validate();
        $this->analysisId = CanonicalUlid::normalize($data['analysisId']);
        $this->revision = (int) $data['expectedRevision'];
        $this->text = $data['text'];
    }
}
