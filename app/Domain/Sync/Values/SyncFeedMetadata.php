<?php

namespace App\Domain\Sync\Values;

final class SyncFeedMetadata
{
    private function __construct() {}

    public static function normalize(string $value): string
    {
        return strtolower(trim($value));
    }
}
