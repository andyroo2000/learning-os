<?php

namespace App\Domain\Content\Actions;

use App\Domain\Content\Models\ContentEpisode;
use App\Domain\Content\Support\ContentEpisodeId;
use App\Domain\Content\Support\ConvoLabUserId;

final class ShowContentEpisodeAction
{
    public function __construct(private readonly ListContentEpisodesAction $listAction) {}

    public function handle(int $userId, string $convoLabUserId, string $episodeId): ContentEpisode
    {
        $convoLabUserId = ConvoLabUserId::normalize($convoLabUserId);
        $episodeId = ContentEpisodeId::normalize($episodeId);

        // Preserve Convo Lab deep links during generation; only its list route hides episodes missing content relations.
        return ContentEpisode::query()
            ->whereKey($episodeId)
            ->where('user_id', $userId)
            ->where('convolab_user_id', $convoLabUserId)
            ->with($this->listAction->detailRelations(includeCourseEpisodes: true))
            ->firstOrFail();
    }
}
