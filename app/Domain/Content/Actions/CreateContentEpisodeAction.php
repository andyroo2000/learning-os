<?php

namespace App\Domain\Content\Actions;

use App\Domain\Content\Data\CreateContentEpisodeData;
use App\Domain\Content\Models\ContentEpisode;
use App\Domain\Content\Support\ContentSourceLock;
use App\Domain\Content\Support\ContentSourceSystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CreateContentEpisodeAction
{
    /**
     * Legacy Episode creates do not carry a client ID, so retries intentionally create a new Episode.
     */
    public function handle(CreateContentEpisodeData $data): ContentEpisode
    {
        return DB::transaction(function () use ($data): ContentEpisode {
            ContentSourceLock::acquireConvoLab(DB::connection());

            $episode = new ContentEpisode;
            $episode->id = (string) Str::uuid();
            $episode->user_id = $data->userId;
            $episode->convolab_user_id = $data->convoLabUserId;
            $episode->source_system = ContentSourceSystem::LEARNING_OS;
            $episode->title = $data->title;
            $episode->source_text = $data->sourceText;
            $episode->target_language = $data->targetLanguage;
            $episode->native_language = $data->nativeLanguage;
            $episode->content_type = 'dialogue';
            $episode->jlpt_level = $data->jlptLevel;
            $episode->auto_generate_audio = $data->autoGenerateAudio;
            $episode->status = 'draft';
            $episode->is_sample_content = false;
            $episode->audio_speed = $data->audioSpeed;
            $episode->save();

            return $episode;
        });
    }
}
