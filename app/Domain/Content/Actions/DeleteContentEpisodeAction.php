<?php

namespace App\Domain\Content\Actions;

use App\Domain\Content\Models\ContentEpisode;
use App\Domain\Content\Models\ContentEpisodeTombstone;
use App\Domain\Content\Support\ContentEpisodeId;
use App\Domain\Content\Support\ContentSourceLock;
use App\Domain\Content\Support\ConvoLabUserId;
use Illuminate\Support\Facades\DB;

final class DeleteContentEpisodeAction
{
    /**
     * A retry after a successful hard delete returns false because ownership can no longer be proven.
     */
    public function handle(int $userId, string $convoLabUserId, string $episodeId): bool
    {
        $convoLabUserId = ConvoLabUserId::normalize($convoLabUserId);
        $episodeId = ContentEpisodeId::normalize($episodeId);

        return DB::transaction(function () use ($userId, $convoLabUserId, $episodeId): bool {
            ContentSourceLock::acquireConvoLab(DB::connection());

            $episode = ContentEpisode::query()
                ->whereKey($episodeId)
                ->where('user_id', $userId)
                ->where('convolab_user_id', $convoLabUserId)
                ->lockForUpdate()
                ->first();

            if ($episode === null) {
                return false;
            }

            $tombstone = ContentEpisodeTombstone::query()
                ->whereKey($episodeId)
                ->lockForUpdate()
                ->first();

            if ($tombstone !== null && (
                $tombstone->user_id !== $userId
                || ! hash_equals($tombstone->convolab_user_id, $convoLabUserId)
            )) {
                return false;
            }

            if ($tombstone === null) {
                $tombstone = new ContentEpisodeTombstone;
                $tombstone->episode_id = $episodeId;
                $tombstone->user_id = $userId;
                $tombstone->convolab_user_id = $convoLabUserId;
            }
            $tombstone->deleted_at = now();
            $tombstone->save();

            $episode->delete();

            return true;
        });
    }
}
