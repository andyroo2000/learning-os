<?php

namespace Tests\Unit\Study;

use App\Domain\Study\Support\StudyImportCanonicalAnswer;
use PHPUnit\Framework\TestCase;

class StudyImportCanonicalAnswerTest extends TestCase
{
    public function test_maps_named_fields_without_guessing_positions_or_including_media(): void
    {
        $answer = StudyImportCanonicalAnswer::fromFields(
            ['AudioWord', 'Meaning', 'ExpressionReading', 'Expression', 'Photo', 'Notes'],
            "[sound:original.mp3]\x1fCat &amp; dog\x1f猫[ねこ]と犬[いぬ]\x1f<b>猫と犬</b>\x1f<img src='x'>\x1f",
        );
        $this->assertSame([
            'meaning' => 'Cat & dog', 'expressionReading' => '猫[ねこ]と犬[いぬ]',
            'expression' => '猫と犬', 'notes' => null,
        ], $answer);
    }

    public function test_unsupported_ambiguous_or_incomplete_fields_keep_legacy_fallback(): void
    {
        $this->assertNull(StudyImportCanonicalAnswer::fromFields(['Front', 'Back'], "猫\x1fcat"));
        $this->assertNull(StudyImportCanonicalAnswer::fromFields(['Expression', 'Meaning'], '猫'));
        $this->assertNull(StudyImportCanonicalAnswer::fromFields(['Expression', 'expression', 'Meaning'], "猫\x1f犬\x1fcat"));
        $this->assertNull(StudyImportCanonicalAnswer::fromFields(['Expression', 'Meaning'], "猫\x1f"));
    }
}
