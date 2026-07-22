<?php

namespace App\Domain\Admin\Support;

use Illuminate\Support\Str;
use InvalidArgumentException;

final class AdminSentenceScriptTestId
{
    public static function normalize(string $testId): string
    {
        $testId = strtolower(trim($testId));
        if (! Str::isUuid($testId)) {
            throw new InvalidArgumentException('Sentence test ID must be a UUID.');
        }

        return $testId;
    }
}
