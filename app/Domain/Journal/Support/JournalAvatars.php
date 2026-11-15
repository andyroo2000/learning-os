<?php

namespace App\Domain\Journal\Support;

final class JournalAvatars
{
    public const NAMES = [
        'male-01-v1',
        'male-02-v1',
        'male-03-v1',
        'female-01-v1',
        'female-02-v1',
        'female-03-v1',
        'professor-male-v1',
        'professor-female-v1',
        'male-01-v2',
        'male-02-v2',
        'male-03-v2',
        'female-01-v2',
        'female-02-v2',
        'female-03-v2',
        'professor-male-v2',
        'professor-female-v2',
    ];

    public static function url(string $name): string
    {
        return route('journal.avatar', ['avatar' => $name], false);
    }

    public static function tutor(?array $tutor): ?array
    {
        if ($tutor === null) {
            return null;
        }
        $name = match ($tutor['avatar'] ?? '') {
            'hana-v1' => 'professor-female-v2',
            'ren-v1' => 'professor-male-v2',
            default => null,
        };

        return [...array_intersect_key($tutor, array_flip(['id', 'name', 'gender', 'avatar'])),
            'avatarUrl' => $name === null ? null : self::url($name)];
    }
}
