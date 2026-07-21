<?php

namespace App\Domain\Content\Support;

use App\Domain\Content\Models\ContentAudioScript;

final class ContentAudioScriptGeneration
{
    public const STALE_AFTER_SECONDS = 180;

    public static function isActive(ContentAudioScript $script): bool
    {
        return $script->status === 'generating'
            && $script->updated_at?->isAfter(now()->subSeconds(self::STALE_AFTER_SECONDS));
    }
}
