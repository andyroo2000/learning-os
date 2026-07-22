<?php

namespace App\Domain\Admin\Support;

use App\Domain\Admin\Models\AdminSentenceScriptTest;
use InvalidArgumentException;

final class AdminSentenceScriptCursor
{
    /** @return array{createdAt: string, id: string} */
    public static function decode(string $cursor): array
    {
        $cursor = trim($cursor);
        if ($cursor === '' || strlen($cursor) > 160) {
            throw new InvalidArgumentException('Sentence test cursor is invalid.');
        }
        $padding = (4 - strlen($cursor) % 4) % 4;
        $decoded = base64_decode(strtr($cursor, '-_', '+/').str_repeat('=', $padding), true);
        if (! is_string($decoded)) {
            throw new InvalidArgumentException('Sentence test cursor is invalid.');
        }

        [$createdAt, $id] = array_pad(explode('|', $decoded, 2), 2, null);
        if (! is_string($createdAt) || ! self::isTimestamp($createdAt) || ! is_string($id)) {
            throw new InvalidArgumentException('Sentence test cursor is invalid.');
        }

        try {
            $id = AdminSentenceScriptTestId::normalize($id);
        } catch (InvalidArgumentException) {
            throw new InvalidArgumentException('Sentence test cursor is invalid.');
        }

        return ['createdAt' => $createdAt, 'id' => $id];
    }

    public static function encode(AdminSentenceScriptTest $test): string
    {
        $value = $test->created_at->format('Y-m-d H:i:s.v').'|'.$test->id;

        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function isTimestamp(string $value): bool
    {
        if (preg_match(
            '/^(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2}):(\d{2})\.\d{3}$/',
            $value,
            $parts,
        ) !== 1) {
            return false;
        }

        return checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])
            && (int) $parts[4] <= 23
            && (int) $parts[5] <= 59
            && (int) $parts[6] <= 59;
    }
}
