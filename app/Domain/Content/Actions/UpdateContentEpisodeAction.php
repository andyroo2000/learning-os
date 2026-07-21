<?php

namespace App\Domain\Content\Actions;

use App\Domain\Content\Data\UpdateContentEpisodeData;
use App\Domain\Content\Models\ContentEpisode;
use App\Domain\Content\Support\ContentEpisodeId;
use App\Domain\Content\Support\ConvoLabUserId;
use Illuminate\Support\Facades\DB;

final class UpdateContentEpisodeAction
{
    public function handle(
        int $userId,
        string $convoLabUserId,
        string $episodeId,
        UpdateContentEpisodeData $data,
    ): bool {
        $convoLabUserId = ConvoLabUserId::normalize($convoLabUserId);
        $episodeId = ContentEpisodeId::normalize($episodeId);

        return DB::transaction(function () use ($userId, $convoLabUserId, $episodeId, $data): bool {
            $episode = ContentEpisode::query()
                ->whereKey($episodeId)
                ->where('user_id', $userId)
                ->where('convolab_user_id', $convoLabUserId)
                ->lockForUpdate()
                ->first();

            if ($episode === null) {
                return false;
            }

            if ($data->hasTitle) {
                $episode->title = $data->title;
            }
            if ($data->hasStatus) {
                $episode->status = $data->status;
            }

            $episode->touch();

            return true;
        });
    }
}
