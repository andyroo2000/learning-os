<?php

namespace App\Domain\Content\Actions;

use App\Domain\Content\Models\ContentEpisode;
use App\Domain\Content\Support\ContentEpisodeId;
use Illuminate\Support\Facades\DB;

final class DeleteContentEpisodeAction
{
    /**
     * A retry after a successful hard delete returns false because ownership can no longer be proven.
     */
    public function handle(int $userId, string $episodeId): bool
    {
        $episodeId = ContentEpisodeId::normalize($episodeId);

        return DB::transaction(function () use ($userId, $episodeId): bool {
            $episode = ContentEpisode::query()
                ->whereKey($episodeId)
                ->where('user_id', $userId)
                ->lockForUpdate()
                ->first();

            if ($episode === null) {
                return false;
            }

            $episode->delete();

            return true;
        });
    }
}
