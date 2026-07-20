<?php

namespace App\Domain\Content\Actions;

use App\Domain\Content\Models\ContentCourse;
use Illuminate\Database\Eloquent\Collection;

final class ListContentCoursesAction
{
    /** @return Collection<int, ContentCourse> */
    public function handle(
        int $userId,
        int $limit,
        int $offset,
        bool $library,
        ?string $status,
    ): Collection {
        $query = ContentCourse::query()
            ->where('user_id', $userId)
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->offset($offset);

        if ($status === 'draft') {
            $query->where('status', 'draft');
        } elseif ($status !== 'all') {
            $query->where('status', '!=', 'draft');
        }

        if ($library) {
            return $query
                ->withCount('coreItems')
                ->with([
                    'courseEpisodes' => fn ($query) => $query->orderBy('sort_order')->orderBy('id')->limit(1),
                    'courseEpisodes.episode.dialogue.sentences:id,dialogue_id',
                ])
                ->get();
        }

        return $query->with($this->detailRelations())->get();
    }

    /** @return array<string, mixed> */
    public function detailRelations(): array
    {
        return [
            'coreItems' => fn ($query) => $query->orderBy('id'),
            'courseEpisodes' => fn ($query) => $query->orderBy('sort_order')->orderBy('id'),
            'courseEpisodes.episode',
        ];
    }
}
