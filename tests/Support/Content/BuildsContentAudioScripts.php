<?php

namespace Tests\Support\Content;

use App\Domain\Content\Models\ContentAudioScript;
use App\Domain\Content\Models\ContentAudioScriptMedia;
use App\Domain\Content\Models\ContentAudioScriptRender;
use App\Domain\Content\Models\ContentAudioScriptSegment;
use App\Domain\Content\Models\ContentEpisode;
use App\Domain\Content\Support\ContentAudioScriptInput;
use App\Domain\Content\Support\ContentSourceSystem;
use App\Models\User;
use Illuminate\Support\Str;

trait BuildsContentAudioScripts
{
    private string $convoLabUserId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->convoLabUserId = (string) Str::uuid();
    }

    private function authenticateWrite(User $user): void
    {
        $this->asConvoLabBrowser($user, convoLabUserId: $this->convoLabUserId);
    }

    /** @return array{ContentEpisode, ContentAudioScript} */
    private function script(User $user, array $attributes = []): array
    {
        $episodeAttributes = $attributes['episode'] ?? [];
        $scriptAttributes = $attributes['script'] ?? [];
        $episode = ContentEpisode::query()->forceCreate([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'convolab_user_id' => $this->convoLabUserId,
            'source_system' => ContentSourceSystem::CONVOLAB,
            'title' => 'Japanese Script',
            'source_text' => '駅に行きます。',
            'target_language' => 'ja',
            'native_language' => 'en',
            'content_type' => 'script',
            'status' => 'draft',
            'is_sample_content' => false,
            'auto_generate_audio' => false,
            'audio_speed' => 'medium',
            ...$episodeAttributes,
        ]);
        $script = ContentAudioScript::query()->forceCreate([
            'id' => (string) Str::uuid(),
            'episode_id' => $episode->id,
            'status' => 'draft',
            'image_status' => 'pending',
            'voice_id' => ContentAudioScriptInput::DEFAULT_VOICE_ID,
            'voice_provider' => 'google',
            ...$scriptAttributes,
        ]);

        return [$episode, $script];
    }

    private function segment(ContentAudioScript $script, array $attributes = []): ContentAudioScriptSegment
    {
        return ContentAudioScriptSegment::query()->forceCreate([
            'id' => (string) Str::uuid(),
            'script_id' => $script->id,
            'sort_order' => 0,
            'text' => '駅に行きます。',
            'reading' => '駅[えき]に行[い]きます。',
            'translation' => 'I am going to the station.',
            'image_prompt' => 'A train station.',
            'image_status' => 'pending',
            'metadata' => ['japanese' => ['kanji' => '駅に行きます。']],
            ...$attributes,
        ]);
    }

    private function render(ContentAudioScript $script, array $attributes = []): ContentAudioScriptRender
    {
        return ContentAudioScriptRender::query()->forceCreate([
            'id' => (string) Str::uuid(),
            'script_id' => $script->id,
            'speed' => 'medium',
            'numeric_speed' => 0.85,
            'status' => 'ready',
            'audio_url' => '/audio/script.mp3',
            ...$attributes,
        ]);
    }

    private function media(User $user, array $attributes = []): ContentAudioScriptMedia
    {
        return ContentAudioScriptMedia::query()->forceCreate([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'source_kind' => 'generated',
            'source_system' => ContentSourceSystem::CONVOLAB,
            'source_filename' => 'scene.webp',
            'normalized_filename' => 'scene.webp',
            'media_kind' => 'image',
            'content_type' => 'image/webp',
            'storage_path' => 'study-media/user/scene.webp',
            'public_url' => '/uploads/study-media/user/scene.webp',
            ...$attributes,
        ]);
    }
}
