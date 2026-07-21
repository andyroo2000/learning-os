<?php

namespace App\Domain\Content\Actions;

use App\Domain\Content\Data\UpdateContentEpisodeData;
use App\Domain\Content\Models\ContentAudioScriptMedia;
use App\Domain\Content\Models\ContentCourse;
use App\Domain\Content\Models\ContentEpisode;
use App\Domain\Content\Models\ContentEpisodeCourse;
use App\Domain\Content\Support\ContentEpisodeId;
use App\Domain\Content\Support\ContentSourceLock;
use App\Domain\Content\Support\ContentSourceSystem;
use App\Domain\Content\Support\ConvoLabUserId;
use Illuminate\Support\Facades\DB;

final class UpdateContentEpisodeAction
{
    /**
     * Even an empty legacy PATCH promotes imported ownership and must serialize with replacement imports.
     */
    public function handle(
        int $userId,
        string $convoLabUserId,
        string $episodeId,
        UpdateContentEpisodeData $data,
    ): bool {
        $convoLabUserId = ConvoLabUserId::normalize($convoLabUserId);
        $episodeId = ContentEpisodeId::normalize($episodeId);

        return DB::transaction(function () use ($userId, $convoLabUserId, $episodeId, $data): bool {
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

            if ($data->hasTitle) {
                $episode->title = $data->title;
            }
            if ($data->hasStatus) {
                $episode->status = $data->status;
            }

            $episode->source_system = ContentSourceSystem::LEARNING_OS;

            ContentAudioScriptMedia::query()
                ->whereHas('segments.script', function ($query) use ($episodeId): void {
                    $query->where('episode_id', $episodeId);
                })
                ->update(['source_system' => ContentSourceSystem::LEARNING_OS]);

            $courseIds = ContentEpisodeCourse::query()
                ->where('episode_id', $episodeId)
                ->pluck('convolab_course_id');

            $ownedCourseIds = ContentCourse::query()
                ->whereKey($courseIds)
                ->where('user_id', $userId)
                ->where('convolab_user_id', $convoLabUserId)
                ->pluck('id');

            ContentCourse::query()
                ->whereKey($ownedCourseIds)
                ->update(['source_system' => ContentSourceSystem::LEARNING_OS]);

            ContentEpisodeCourse::query()
                ->whereIn('convolab_course_id', $ownedCourseIds)
                ->update(['source_system' => ContentSourceSystem::LEARNING_OS]);

            $episode->touch();

            return true;
        });
    }
}
