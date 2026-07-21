<?php

namespace App\Domain\Content\Actions;

use App\Domain\Content\Data\UpdateContentEpisodeData;
use App\Domain\Content\Models\ContentEpisode;
use App\Domain\Content\Support\ContentEpisodeId;
use Illuminate\Support\Facades\DB;

final class UpdateContentEpisodeAction
{
    public function handle(int $userId, string $episodeId, UpdateContentEpisodeData $data): bool
    {
        $episodeId = ContentEpisodeId::normalize($episodeId);

        return DB::transaction(function () use ($userId, $episodeId, $data): bool {
            $episode = ContentEpisode::query()
                ->whereKey($episodeId)
                ->where('user_id', $userId)
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
