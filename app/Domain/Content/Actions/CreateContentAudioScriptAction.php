<?php

namespace App\Domain\Content\Actions;

use App\Domain\Content\Data\CreateContentAudioScriptData;
use App\Domain\Content\Models\ContentAudioScript;
use App\Domain\Content\Models\ContentEpisode;
use App\Domain\Content\Support\ContentSourceLock;
use App\Domain\Content\Support\ContentSourceSystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CreateContentAudioScriptAction
{
    public function handle(CreateContentAudioScriptData $data): ContentEpisode
    {
        return DB::transaction(function () use ($data): ContentEpisode {
            ContentSourceLock::acquireConvoLab(DB::connection());

            $episode = new ContentEpisode;
            $episode->id = (string) Str::uuid();
            $episode->user_id = $data->userId;
            $episode->convolab_user_id = $data->convoLabUserId;
            $episode->source_system = ContentSourceSystem::LEARNING_OS;
            $episode->title = 'Japanese Script';
            $episode->source_text = $data->sourceText;
            $episode->target_language = 'ja';
            $episode->native_language = 'en';
            $episode->content_type = 'script';
            $episode->auto_generate_audio = false;
            $episode->status = 'draft';
            $episode->is_sample_content = false;
            $episode->audio_speed = 'medium';
            $episode->save();

            $script = new ContentAudioScript;
            $script->id = (string) Str::uuid();
            $script->episode_id = $episode->id;
            $script->status = 'draft';
            $script->image_status = 'pending';
            $script->voice_id = $data->voiceId;
            $script->voice_provider = 'google';
            $script->save();

            $episode->setRelation('audioScript', $script);

            return $episode;
        });
    }
}
