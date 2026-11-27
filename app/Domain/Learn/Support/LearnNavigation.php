<?php

namespace App\Domain\Learn\Support;

final class LearnNavigation
{
    public static function completed(array $state): bool
    {
        return ($state['completed'] ?? false) || $state['phase'] === 'complete' || ($state['reviewing'] ?? false);
    }

    public static function remember(array $state): array
    {
        $bookmark = array_intersect_key($state, array_flip(['index', 'answered', 'reviewing', 'reviewRun']));

        return [...$state, 'completed' => self::completed($state),
            'bookmarks' => [...($state['bookmarks'] ?? []), $state['phase'] => $bookmark]];
    }

    public static function go(array $state, string $phase, array $lesson): array
    {
        abort_if(in_array($phase, ['ready', 'exercise'], true) && count($lesson['exercises']) === 0, 422, 'This point has no dedicated workbook exercise set.');
        abort_if($phase === 'complete' && ! self::completed($state), 422, 'Finish the lesson before reflecting on this set.');
        if ($phase === $state['phase']) {
            return $state;
        }
        $state = self::remember($state);
        $bookmark = $state['bookmarks'][$phase] ?? ['index' => 0, 'answered' => false];
        $next = [...$state, 'reviewing' => false, ...$bookmark, 'phase' => $phase];

        return self::restoreAnswer($next);
    }

    public static function restoreAnswer(array $state): array
    {
        if ($state['answered'] || ! in_array($state['phase'], ['check', 'exercise'], true)) {
            return $state;
        }
        $key = LearnProgress::stepKey($state);
        foreach ($state['turns'] as $turn) {
            if ($turn['stepKey'] === $key && in_array($turn['kind'], ['answer', 'acknowledge'], true)) {
                return [...$state, 'answered' => true];
            }
        }

        return $state;
    }
}
