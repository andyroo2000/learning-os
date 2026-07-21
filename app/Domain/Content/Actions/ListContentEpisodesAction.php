<?php

namespace App\Domain\Content\Actions;

use App\Domain\Content\Models\ContentEpisode;
use App\Domain\Content\Support\ConvoLabUserId;
use Illuminate\Database\Eloquent\Collection;

final class ListContentEpisodesAction
{
    /**
     * @return Collection<int, ContentEpisode>
     */
    public function handle(
        int $userId,
        string $convoLabUserId,
        int $limit,
        int $offset,
        bool $library,
    ): Collection {
        $convoLabUserId = ConvoLabUserId::normalize($convoLabUserId);

        $query = ContentEpisode::query()
            ->where('user_id', $userId)
            ->where('convolab_user_id', $convoLabUserId)
            ->where(function ($query): void {
                $query
                    ->where(function ($query): void {
                        $query->where('content_type', 'dialogue')->whereHas('dialogue');
                    })
                    ->orWhere(function ($query): void {
                        $query->where('content_type', 'script')->whereHas('audioScript');
                    });
            })
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->offset($offset);

        if ($library) {
            return $query->with([
                'dialogue.speakers:id,dialogue_id,proficiency',
                'audioScript' => fn ($query) => $query->withCount('segments'),
            ])->get();
        }

        return $query->with($this->detailRelations())->get();
    }

    /**
     * @return array<string, mixed>
     */
    public function detailRelations(bool $includeCourseEpisodes = false): array
    {
        $relations = [
            'dialogue.sentences' => fn ($query) => $query->orderBy('sort_order')->orderBy('id'),
            'dialogue.speakers',
            'audioScript.segments' => fn ($query) => $query->with('imageMedia')->orderBy('sort_order'),
            'audioScript.renders' => fn ($query) => $query->orderBy('numeric_speed'),
            'images' => fn ($query) => $query->orderBy('sort_order')->orderBy('id'),
        ];

        if ($includeCourseEpisodes) {
            $relations['courseEpisodes'] = fn ($query) => $query->orderBy('sort_order')->orderBy('id');
        }

        return $relations;
    }
}
