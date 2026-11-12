<?php

namespace App\Domain\Study\Support;

use App\Support\Japanese\BracketFurigana;

final class StudyCaptureReading
{
    public static function needsReading(string $expression): bool
    {
        return BracketFurigana::needsReading($expression);
    }

    public static function validate(string $expression, mixed $reading): string
    {
        return BracketFurigana::validate($expression, $reading);
    }
}
