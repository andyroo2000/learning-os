<?php

namespace App\Domain\Journal\Support;

use App\Support\Japanese\BracketFurigana;
use RuntimeException;

final class JournalReplacements
{
    public static function prepare(array $feedback): array
    {
        $editable = collect($feedback['segments'])->contains(fn (array $span) => array_key_exists('replacement', $span));
        if (! $editable) {
            return $feedback;
        }
        $text = '';
        $reading = '';
        foreach ($feedback['segments'] as $span) {
            self::validate($span);
            $applied = $span['kind'] === 'correction';
            $text .= $applied ? $span['replacement'] : $span['text'];
            $reading .= $applied ? $span['replacementReading'] : $span['reading'];
        }
        if (trim($text) === '' || mb_strlen($text) > 30000) {
            throw new RuntimeException('The corrected journal text is empty or too long.');
        }

        return [...$feedback, 'correctedText' => $text, 'correctedReading' => $reading, 'editVersion' => 1];
    }

    private static function validate(array $span): void
    {
        if (! array_key_exists('replacement', $span) || ! array_key_exists('replacementReading', $span)) {
            throw new RuntimeException('Every editable journal span needs a replacement pair.');
        }
        if ($span['kind'] === 'plain') {
            self::assertUnchanged($span);

            return;
        }
        if (! is_string($span['replacement'])) {
            throw new RuntimeException('A highlighted journal span needs an exact replacement.');
        }
        BracketFurigana::validate($span['replacement'], $span['replacementReading']);
    }

    private static function assertUnchanged(array $span): void
    {
        if ($span['replacement'] !== null || $span['replacementReading'] !== null) {
            throw new RuntimeException('Plain journal text cannot be replaced.');
        }
    }
}
