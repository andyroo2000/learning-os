<?php

namespace App\Domain\Admin\Support;

final class AdminScriptLabAudio
{
    public static function audioUrl(string $renderingId): string
    {
        return "/api/convolab/admin/script-lab/audio/{$renderingId}";
    }

    public static function storagePath(string $actorConvoLabUserId, string $renderingId): string
    {
        return "admin-script-lab-audio/{$actorConvoLabUserId}/{$renderingId}.mp3";
    }

    public static function ownsPath(
        string $actorConvoLabUserId,
        string $renderingId,
        ?string $path,
    ): bool {
        return is_string($path)
            && hash_equals(self::storagePath($actorConvoLabUserId, $renderingId), $path);
    }
}
