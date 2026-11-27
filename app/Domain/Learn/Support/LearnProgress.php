<?php

namespace App\Domain\Learn\Support;

final class LearnProgress
{
    public static function initial(): array
    {
        return ['phase' => 'check', 'index' => 0, 'answered' => false, 'turns' => []];
    }

    public static function stepKey(array $state): string
    {
        return ($state['reviewing'] ?? false)
            ? 'review:'.$state['reviewRun'].':'.$state['index']
            : $state['phase'].':'.$state['index'];
    }

    public static function review(array $state): array
    {
        abort_unless($state['phase'] === 'complete', 422, 'Finish the lesson before reviewing its explanations.');

        $state = LearnNavigation::remember($state);

        return [...$state, 'phase' => 'check', 'index' => 0, 'answered' => false,
            'reviewing' => true, 'reviewRun' => ($state['reviewRun'] ?? 0) + 1];
    }

    public static function previous(array $state): array
    {
        abort_unless($state['phase'] === 'check' && $state['index'] > 0, 422, 'There is no previous explanation here.');
        $state = LearnNavigation::remember($state);

        return LearnNavigation::restoreAnswer([...$state, 'index' => $state['index'] - 1, 'answered' => false]);
    }

    public static function advance(array $state, array $lesson): array
    {
        $next = self::advanceStep(LearnNavigation::remember($state), $lesson);

        return LearnNavigation::restoreAnswer([...$next, 'completed' => LearnNavigation::completed($next)]);
    }

    private static function advanceStep(array $state, array $lesson): array
    {
        abort_if($state['phase'] === 'complete', 422, 'This lesson is complete.');
        if ($state['phase'] === 'ready') {
            return LearnNavigation::go($state, 'exercise', $lesson);
        }
        abort_unless($state['phase'] === 'check' || $state['answered'], 422, 'Answer the current question before moving on.');
        $count = count($state['phase'] === 'check' ? $lesson['checks'] : $lesson['exercises']);
        if ($count > $state['index'] + 1) {
            return [...$state, 'index' => $state['index'] + 1, 'answered' => false];
        }

        if (($state['reviewing'] ?? false) || count($lesson['exercises']) === 0) {
            return [...$state, 'phase' => 'complete', 'index' => 0, 'answered' => false, 'reviewing' => false];
        }

        return [...$state, 'phase' => $state['phase'] === 'check' ? 'ready' : 'complete', 'index' => 0, 'answered' => false];
    }

    public static function preview(array $state, array $lesson): ?array
    {
        if ($state['phase'] === 'complete') {
            return null;
        }
        if ($state['phase'] === 'exercise' && ! $state['answered']) {
            return null;
        }
        $next = self::advance($state, $lesson);

        return ['phase' => $next['phase'], 'index' => $next['index'], 'answered' => $next['answered'],
            'reviewing' => $next['reviewing'] ?? false, 'completed' => $next['completed'],
            'prompt' => app(LearnCatalog::class)->prompt($lesson, $next)];
    }

    public static function reply(array $state, array $input, array $feedback): array
    {
        $kind = self::replyKind($state['phase'], $input['action'] === 'message' ? $feedback['kind'] : $input['action']);
        $turn = ['id' => $input['requestId'], 'stepKey' => self::stepKey($state),
            'text' => $input['text'], ...$feedback, 'kind' => $kind,
            'segments' => $kind === 'answer' ? $feedback['segments'] : []];

        return [...$state, 'answered' => $state['answered'] || in_array($kind, ['answer', 'acknowledge'], true),
            'turns' => [...$state['turns'], $turn]];
    }

    private static function replyKind(string $phase, string $kind): string
    {
        if (! in_array($phase, ['check', 'exercise'], true)) {
            return 'clarify';
        }
        if ($kind === 'acknowledge' && $phase !== 'check') {
            return 'clarify';
        }

        return $kind;
    }
}
