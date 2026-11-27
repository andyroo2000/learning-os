<?php

namespace App\Domain\Learn\Support;

use Illuminate\Container\Attributes\Scoped;
use Illuminate\Support\Facades\File;

#[Scoped]
final class LearnCatalog
{
    private ?array $lessons = null;

    public function all(): array
    {
        return $this->lessons ??= File::json(resource_path('learn/quartet-one.json'));
    }

    public function find(string $id): array
    {
        return collect($this->all())->firstWhere('id', $id) ?? abort(404);
    }

    public function publicLesson(array $lesson): array
    {
        return collect($lesson)->only(['id', 'title', 'subtitle', 'source', 'reference'])->all()
            + ['exerciseCount' => count($lesson['exercises']), 'checkCount' => count($lesson['checks'])];
    }

    public function prompt(array $lesson, array $state): ?array
    {
        $items = match ($state['phase']) {
            'check' => $lesson['checks'],
            'exercise' => $lesson['exercises'],
            default => [],
        };

        return $items[$state['index']] ?? null;
    }
}
