<?php

namespace App\Domain\Study\Support;

use RuntimeException;

final class StudyCaptureReading
{
    // Explicit Script avoids Script_Extensions treating Japanese punctuation as Han.
    private const KANJI = '/[\p{sc=Han}々〆]/u';

    private const ANNOTATION = '/([\p{sc=Han}々〆]+)\[([ぁ-ゖァ-ヺー]+)\]/u';

    public static function needsReading(string $expression): bool
    {
        return preg_match(self::KANJI, $expression) === 1;
    }

    public static function validate(string $expression, mixed $reading): string
    {
        if (! is_string($reading)) {
            throw new RuntimeException('Invalid captured sentence reading.');
        }
        $restored = preg_replace(self::ANNOTATION, '$1', $reading);
        $unannotated = preg_replace(self::ANNOTATION, '', $reading);
        if ($restored !== $expression || self::needsReading($unannotated)) {
            throw new RuntimeException('Captured sentence reading must annotate every kanji and preserve the source text.');
        }

        return $reading;
    }
}
