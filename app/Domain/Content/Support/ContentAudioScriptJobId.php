<?php

namespace App\Domain\Content\Support;

use Illuminate\Support\Str;
use InvalidArgumentException;

final class ContentAudioScriptJobId
{
    public static function normalize(string $jobId): string
    {
        $jobId = strtolower(trim($jobId));
        if (! Str::isUuid($jobId)) {
            throw new InvalidArgumentException('Script generation job ID is invalid.');
        }

        return $jobId;
    }
}
