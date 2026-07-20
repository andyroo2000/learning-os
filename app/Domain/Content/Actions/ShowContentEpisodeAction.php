<?php

namespace App\Domain\Content\Actions;

use App\Domain\Content\Models\ContentEpisode;

final class ShowContentEpisodeAction
{
    public function __construct(private readonly ListContentEpisodesAction $listAction) {}

    public function handle(int $userId, string $episodeId): ContentEpisode
    {
        return ContentEpisode::query()
            ->whereKey($episodeId)
            ->where('user_id', $userId)
            ->with($this->listAction->detailRelations(includeCourseEpisodes: true))
            ->firstOrFail();
    }
}
