<?php

namespace App\Domain\Journal\Data;

use App\Support\Identifiers\CanonicalUlid;
use Illuminate\Support\Facades\Validator;

final readonly class JournalDocumentInput
{
    public string $analysisId;

    public int $revision;

    public string $suggestionId;

    public bool $applied;

    public function __construct(array $input)
    {
        $data = Validator::make($input, [
            'analysisId' => ['required', 'ulid'],
            'expectedRevision' => ['required', 'integer', 'min:0', 'max:2147483646'],
            'suggestionId' => ['required', 'string', 'regex:/^[0-9A-HJKMNP-TV-Z]{26}:[0-9]{1,5}$/i'],
            'applied' => ['required', 'boolean'],
        ])->validate();
        $this->analysisId = CanonicalUlid::normalize($data['analysisId']);
        $this->revision = (int) $data['expectedRevision'];
        $this->suggestionId = strtolower($data['suggestionId']);
        $this->applied = (bool) $data['applied'];
    }
}
