<?php

namespace Tests\Feature\Content;

use App\Domain\Content\Models\ContentAudioScript;
use App\Domain\Content\Models\ContentAudioScriptMedia;
use App\Domain\Content\Models\ContentAudioScriptRender;
use App\Domain\Content\Models\ContentAudioScriptSegment;
use App\Domain\Content\Models\ContentDialogue;
use App\Domain\Content\Models\ContentEpisode;
use App\Domain\Content\Models\ContentEpisodeCourse;
use App\Domain\Content\Models\ContentEpisodeTombstone;
use App\Domain\Content\Models\ContentImage;
use App\Domain\Content\Models\ContentSentence;
use App\Domain\Content\Models\ContentSpeaker;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ContentEpisodeApiTest extends TestCase
{
    use RefreshDatabase;

    private string $convoLabUserId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->convoLabUserId = (string) Str::uuid();
    }

    public function test_episode_reads_require_authentication(): void
    {
        $this->getJson('/api/convolab/episodes')->assertUnauthorized();
        $this->getJson('/api/convolab/episodes/'.Str::uuid())->assertUnauthorized();
    }

    public function test_read_only_content_models_keep_mass_assignment_guarded(): void
    {
        $models = [
            ContentEpisode::class,
            ContentDialogue::class,
            ContentSpeaker::class,
            ContentSentence::class,
            ContentImage::class,
            ContentAudioScript::class,
            ContentAudioScriptMedia::class,
            ContentAudioScriptSegment::class,
            ContentAudioScriptRender::class,
            ContentEpisodeCourse::class,
            ContentEpisodeTombstone::class,
        ];

        foreach ($models as $model) {
            $this->assertSame(['*'], (new $model)->getGuarded());
        }
    }

    public function test_episode_reads_require_a_valid_effective_convolab_user(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/convolab/episodes')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['convolabUserId']);
        $this->withHeader('X-Convo-Lab-User-Id', 'not-a-uuid')
            ->getJson('/api/convolab/episodes/'.Str::uuid())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['convolabUserId']);
    }

    public function test_library_list_preserves_compact_convolab_shape_and_owner_boundary(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $this->authenticate($user);

        $dialogueEpisode = $this->dialogueEpisode($user, now()->subMinute());
        $scriptEpisode = $this->scriptEpisode($user, now());
        $this->dialogueEpisode($otherUser, now()->addMinute());
        $this->dialogueEpisode($user, now()->addMinutes(2), (string) Str::uuid());
        ContentEpisode::query()->forceCreate($this->episodeAttributes($user, 'dialogue', now()->addMinutes(2)));

        $this->getJson('/api/convolab/episodes?library=true&limit=10&offset=0')
            ->assertOk()
            ->assertJsonCount(2)
            ->assertJsonStructure(['0' => ['dialogue', 'audioScript']])
            ->assertJsonPath('0.id', $scriptEpisode->id)
            ->assertJsonPath('0.audioScript.status', 'ready')
            ->assertJsonPath('0.audioScript._count.segments', 2)
            ->assertJsonPath('0.dialogue', null)
            ->assertJsonPath('1.id', $dialogueEpisode->id)
            ->assertJsonPath('1.dialogue.speakers.0.proficiency', 'beginner')
            ->assertJsonMissingPath('1.userId')
            ->assertJsonMissingPath('1.dialogue.sentences');
    }

    public function test_full_list_and_show_return_ordered_nested_compatibility_data(): void
    {
        $user = User::factory()->create();
        $this->authenticate($user);
        $episode = $this->dialogueEpisode($user, now());
        $this->withoutMiddleware(TrimStrings::class);
        $this->withHeader('X-Convo-Lab-User-Id', '  '.strtoupper($this->convoLabUserId).'  ');

        $listResponse = $this->getJson('/api/convolab/episodes');
        $listResponse
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonStructure(['0' => ['dialogue', 'audioScript', 'images']])
            ->assertJsonMissingPath('0.courseEpisodes');
        $this->assertDialogueShape($listResponse, '0', $episode);

        $showResponse = $this->getJson('/api/convolab/episodes/'.$episode->id);
        $showResponse
            ->assertOk()
            ->assertHeader('Cache-Control', 'max-age=60, private');
        $this->assertDialogueShape($showResponse, null, $episode);
    }

    public function test_show_hides_missing_and_other_owner_episodes(): void
    {
        $owner = User::factory()->create();
        $viewer = User::factory()->create();
        $episode = $this->dialogueEpisode($owner, now());
        $sameAccountOtherSource = $this->dialogueEpisode($viewer, now(), (string) Str::uuid());
        $this->authenticate($viewer);

        $this->getJson('/api/convolab/episodes/'.$episode->id)->assertNotFound();
        $this->getJson('/api/convolab/episodes/'.$sameAccountOtherSource->id)->assertNotFound();
        $this->getJson('/api/convolab/episodes/'.Str::uuid())->assertNotFound();
    }

    public function test_show_preserves_legacy_deep_links_for_owner_episodes_still_missing_generated_content(): void
    {
        $user = User::factory()->create();
        $this->authenticate($user);
        $episode = ContentEpisode::query()->forceCreate($this->episodeAttributes($user, 'dialogue', now()));

        $this->getJson('/api/convolab/episodes')->assertOk()->assertJsonCount(0);
        $this->getJson('/api/convolab/episodes/'.$episode->id)
            ->assertOk()
            ->assertJsonStructure(['dialogue', 'audioScript'])
            ->assertJsonPath('id', $episode->id)
            ->assertJsonPath('dialogue', null);
    }

    public function test_list_validates_boolean_and_bounded_offset_pagination(): void
    {
        $this->authenticate(User::factory()->create());

        $this->getJson('/api/convolab/episodes?library=false&limit=1&offset=0')->assertOk();

        $this->getJson('/api/convolab/episodes?library=maybe&limit=0&offset=-1')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['library', 'limit', 'offset']);

        $this->getJson('/api/convolab/episodes?limit=101&offset=1000001')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['limit', 'offset']);
    }

    public function test_library_query_count_stays_bounded_as_episode_count_grows(): void
    {
        $user = User::factory()->create();
        $this->authenticate($user);
        foreach (range(1, 5) as $index) {
            $this->dialogueEpisode($user, now()->subMinutes($index));
            $this->scriptEpisode($user, now()->subMinutes($index + 5));
        }

        DB::enableQueryLog();
        DB::flushQueryLog();

        try {
            $this->getJson('/api/convolab/episodes?library=true&limit=20')->assertOk()->assertJsonCount(10);
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }

        $this->assertCount(4, $queries, 'Library reads should use one episode query and three bounded eager-load queries.');
    }

    private function dialogueEpisode(
        User $user,
        mixed $updatedAt,
        ?string $convoLabUserId = null,
    ): ContentEpisode {
        $episode = ContentEpisode::query()->forceCreate($this->episodeAttributes(
            $user,
            'dialogue',
            $updatedAt,
            $convoLabUserId,
        ));
        $dialogue = ContentDialogue::query()->forceCreate([
            'id' => (string) Str::uuid(),
            'episode_id' => $episode->id,
            'created_at' => now()->subHour(),
            'updated_at' => now(),
        ]);
        $speaker = ContentSpeaker::query()->forceCreate([
            'id' => (string) Str::uuid(),
            'dialogue_id' => $dialogue->id,
            'name' => 'Aki',
            'voice_id' => 'ja-JP-Neural2-B',
            'voice_provider' => 'google',
            'proficiency' => 'beginner',
            'tone' => 'polite',
            'gender' => 'female',
            'color' => 'cyan',
            'avatar_url' => '/api/avatars/voices/ja-aki.jpg',
        ]);
        foreach ([2 => 'Second', 1 => 'First'] as $order => $text) {
            ContentSentence::query()->forceCreate([
                'id' => (string) Str::uuid(),
                'dialogue_id' => $dialogue->id,
                'speaker_id' => $speaker->id,
                'sort_order' => $order,
                'text' => $text,
                'translation' => "Translation {$order}",
                'metadata' => ['japanese' => ['kanji' => $text, 'kana' => $text, 'furigana' => $text]],
                'variations' => [$text.' variation'],
                'selected' => $order === 1,
                'created_at' => now()->subMinutes($order),
                'updated_at' => now(),
            ]);
        }

        return $episode;
    }

    private function scriptEpisode(User $user, mixed $updatedAt): ContentEpisode
    {
        $episode = ContentEpisode::query()->forceCreate($this->episodeAttributes($user, 'script', $updatedAt));
        $script = ContentAudioScript::query()->forceCreate([
            'id' => (string) Str::uuid(),
            'episode_id' => $episode->id,
            'status' => 'ready',
            'image_status' => 'partial',
            'image_error_message' => 'One image pending.',
            'voice_id' => 'ja-JP-Neural2-B',
            'voice_provider' => 'google',
            'generation_metadata' => ['model' => 'gpt-test'],
            'created_at' => now()->subHour(),
            'updated_at' => now(),
        ]);
        foreach ([2, 1] as $order) {
            ContentAudioScriptSegment::query()->forceCreate([
                'id' => (string) Str::uuid(),
                'script_id' => $script->id,
                'sort_order' => $order,
                'text' => "Segment {$order}",
                'translation' => "Translation {$order}",
                'image_status' => 'ready',
                'metadata' => ['order' => $order],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        foreach ([1.0, 0.75] as $speed) {
            ContentAudioScriptRender::query()->forceCreate([
                'id' => (string) Str::uuid(),
                'script_id' => $script->id,
                'speed' => (string) $speed,
                'numeric_speed' => $speed,
                'status' => 'ready',
                'timing_data' => [['startTime' => 0, 'endTime' => 100]],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $episode;
    }

    private function episodeAttributes(
        User $user,
        string $contentType,
        mixed $updatedAt,
        ?string $convoLabUserId = null,
    ): array {
        return [
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'convolab_user_id' => $convoLabUserId ?? $this->convoLabUserId,
            'title' => ucfirst($contentType).' episode',
            'source_text' => 'Source text',
            'target_language' => 'ja',
            'native_language' => 'en',
            'content_type' => $contentType,
            'jlpt_level' => 'N5',
            'auto_generate_audio' => true,
            'status' => 'ready',
            'is_sample_content' => false,
            'audio_speed' => 'medium',
            'created_at' => now()->subDay(),
            'updated_at' => $updatedAt,
        ];
    }

    private function authenticate(User $user): void
    {
        Sanctum::actingAs($user);
        $this->withHeader('X-Convo-Lab-User-Id', $this->convoLabUserId);
    }

    private function assertDialogueShape(mixed $response, ?string $prefix, ContentEpisode $episode): void
    {
        $path = fn (string $suffix): string => $prefix === null ? $suffix : $prefix.'.'.$suffix;
        $response
            ->assertJsonPath($path('id'), $episode->id)
            ->assertJsonPath($path('userId'), $episode->convolab_user_id)
            ->assertJsonPath($path('contentType'), 'dialogue')
            ->assertJsonPath($path('dialogue.sentences.0.order'), 1)
            ->assertJsonPath($path('dialogue.sentences.0.text'), 'First')
            ->assertJsonPath($path('dialogue.sentences.1.order'), 2)
            ->assertJsonPath($path('dialogue.speakers.0.voiceProvider'), 'google')
            ->assertJsonPath($path('audioScript'), null);
    }
}
