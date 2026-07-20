<?php

namespace App\Domain\Content\Actions;

use App\Domain\Content\Models\ContentEpisode;

final class ShowContentEpisodeAction
{
    public function __construct(private readonly ListContentEpisodesAction $listAction) {}

    public function handle(int $userId, string $episodeId): ContentEpisode
    {
        // Preserve Convo Lab deep links during generation; only its list route hides episodes missing content relations.
        return ContentEpisode::query()
            ->whereKey($episodeId)
            ->where('user_id', $userId)
            ->with($this->listAction->detailRelations(includeCourseEpisodes: true))
            ->firstOrFail();
    }
}
