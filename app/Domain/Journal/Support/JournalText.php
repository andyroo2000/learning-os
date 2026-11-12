<?php

namespace App\Domain\Journal\Support;

use Illuminate\Validation\ValidationException;

/** Japanese text and its matching bracket-furigana representation. */
final readonly class JournalText
{
    public function __construct(public string $text, public string $reading) {}

    public function assertEntryLength(): void
    {
        if (trim($this->text) === '' || mb_strlen($this->text) > 30000) {
            throw ValidationException::withMessages(['text' => 'The corrected entry must contain 1–30,000 characters.']);
        }
    }
}
