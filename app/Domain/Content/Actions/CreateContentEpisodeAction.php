<?php

namespace App\Domain\Content\Actions;

use App\Domain\Content\Data\CreateContentEpisodeData;
use App\Domain\Content\Models\ContentEpisode;
use Illuminate\Support\Str;

final class CreateContentEpisodeAction
{
    /**
     * Legacy Episode creates do not carry a client ID, so retries intentionally create a new Episode.
     */
    public function handle(CreateContentEpisodeData $data): ContentEpisode
    {
        $episode = new ContentEpisode;
        $episode->id = (string) Str::uuid();
        $episode->user_id = $data->userId;
        $episode->convolab_user_id = $data->convoLabUserId;
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
    }
}
