<?php

namespace Tests\Feature\Content;

use App\Domain\Content\Models\ContentCourse;
use App\Domain\Content\Models\ContentCourseCoreItem;
use App\Domain\Content\Models\ContentDialogue;
use App\Domain\Content\Models\ContentEpisode;
use App\Domain\Content\Models\ContentEpisodeCourse;
use App\Domain\Content\Models\ContentSentence;
use App\Domain\Content\Models\ContentSpeaker;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ContentCourseApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_course_reads_require_authentication(): void
    {
        $this->getJson('/api/convolab/courses')->assertUnauthorized();
        $this->getJson('/api/convolab/courses/'.Str::uuid())->assertUnauthorized();
    }

    public function test_read_only_course_models_keep_mass_assignment_guarded(): void
    {
        $this->assertSame(['*'], (new ContentCourse)->getGuarded());
        $this->assertSame(['*'], (new ContentCourseCoreItem)->getGuarded());
    }

    public function test_library_list_preserves_compact_shape_filters_drafts_and_scopes_owners(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        Sanctum::actingAs($user);

        $ready = $this->course($user, 'ready', now());
        $this->course($user, 'draft', now()->addMinute());
        $this->course($otherUser, 'ready', now()->addMinutes(2));
        $this->coreItem($ready);
        $episode = $this->episodeWithDialogue($user);
        $this->linkEpisode($ready, $episode, 0);

        $this->getJson('/api/convolab/courses?library=true&limit=20&offset=0')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $ready->id)
            ->assertJsonPath('0._count.coreItems', 1)
            ->assertJsonCount(1, '0.courseEpisodes.0.episode.dialogue.sentences')
            ->assertJsonMissingPath('0.userId')
            ->assertJsonMissingPath('0.coreItems')
            ->assertJsonMissingPath('0.scriptJson');
    }

    public function test_status_filters_preserve_admin_compatibility_semantics(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $ready = $this->course($user, 'ready', now());
        $draft = $this->course($user, 'draft', now()->addMinute());

        $this->getJson('/api/convolab/courses?status=all')
            ->assertOk()
            ->assertJsonCount(2)
            ->assertJsonPath('0.id', $draft->id)
            ->assertJsonPath('1.id', $ready->id);
        $this->getJson('/api/convolab/courses?status=draft')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $draft->id);
        $this->getJson('/api/convolab/courses?status=ready')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $ready->id);
    }

    public function test_full_list_and_show_return_nested_legacy_shape(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $course = $this->course($user, 'ready', now());
        $coreItem = $this->coreItem($course);
        $episode = $this->episodeWithDialogue($user);
        $link = $this->linkEpisode($course, $episode, 3);

        $this->getJson('/api/convolab/courses')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $course->id)
            ->assertJsonPath('0.userId', $course->convolab_user_id)
            ->assertJsonPath('0.scriptJson.0.type', 'narration_L1')
            ->assertJsonPath('0.coreItems.0.id', $coreItem->id)
            ->assertJsonPath('0.courseEpisodes.0.id', $link->id)
            ->assertJsonPath('0.courseEpisodes.0.episode.id', $episode->id)
            ->assertJsonMissingPath('0.courseEpisodes.0.episode.dialogue');

        $this->getJson('/api/convolab/courses/'.$course->id)
            ->assertOk()
            ->assertHeader('Cache-Control', 'max-age=60, private')
            ->assertJsonPath('id', $course->id)
            ->assertJsonPath('coreItems.0.components.0.text', '猫')
            ->assertJsonPath('courseEpisodes.0.order', 3);
    }

    public function test_show_hides_missing_and_other_owner_courses(): void
    {
        $owner = User::factory()->create();
        $viewer = User::factory()->create();
        $course = $this->course($owner, 'ready', now());
        Sanctum::actingAs($viewer);

        $this->getJson('/api/convolab/courses/'.$course->id)->assertNotFound();
        $this->getJson('/api/convolab/courses/'.Str::uuid())->assertNotFound();
    }

    public function test_list_validates_boolean_status_and_bounded_offset_pagination(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/convolab/courses?library=false&limit=1&offset=0')->assertOk();
        $this->getJson('/api/convolab/courses?library=maybe&limit=0&offset=-1&status[]=all')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['library', 'limit', 'offset', 'status']);
        $this->getJson('/api/convolab/courses?limit=101&offset=1000001')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['limit', 'offset']);
    }

    public function test_library_query_count_stays_bounded_as_course_count_grows(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        foreach (range(1, 5) as $index) {
            $course = $this->course($user, 'ready', now()->subMinutes($index));
            $this->coreItem($course);
            $this->linkEpisode($course, $this->episodeWithDialogue($user), 0);
        }

        DB::enableQueryLog();
        DB::flushQueryLog();

        try {
            $this->getJson('/api/convolab/courses?library=true&limit=20')->assertOk()->assertJsonCount(5);
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }

        $this->assertCount(5, $queries, 'Library reads must use a bounded eager-load query set.');
    }

    private function course(User $user, string $status, mixed $updatedAt): ContentCourse
    {
        return ContentCourse::query()->forceCreate([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'convolab_user_id' => (string) Str::uuid(),
            'title' => ucfirst($status).' course',
            'description' => 'Course description',
            'status' => $status,
            'is_sample_content' => false,
            'is_test_course' => false,
            'native_language' => 'en',
            'target_language' => 'ja',
            'max_lesson_duration_minutes' => 30,
            'l1_voice_id' => 'en-US-Neural2-J',
            'l1_voice_provider' => 'google',
            'jlpt_level' => 'N5',
            'speaker1_gender' => 'male',
            'speaker2_gender' => 'female',
            'speaker1_voice_id' => 'ja-JP-Neural2-B',
            'speaker1_voice_provider' => 'google',
            'speaker2_voice_id' => 'ja-JP-Neural2-C',
            'speaker2_voice_provider' => 'google',
            'script_json' => [['type' => 'narration_L1', 'text' => 'Listen.']],
            'script_units_json' => [['type' => 'pause', 'seconds' => 1]],
            'approx_duration_seconds' => 120,
            'audio_url' => '/audio/course.mp3',
            'timing_data' => [['unitIndex' => 0, 'startTime' => 0, 'endTime' => 1]],
            'created_at' => now()->subDay(),
            'updated_at' => $updatedAt,
        ]);
    }

    private function coreItem(ContentCourse $course): ContentCourseCoreItem
    {
        return ContentCourseCoreItem::query()->forceCreate([
            'id' => (string) Str::uuid(),
            'course_id' => $course->id,
            'text_l2' => '猫',
            'reading_l2' => 'ねこ',
            'translation_l1' => 'cat',
            'complexity_score' => 1.25,
            'source_unit_index' => 2,
            'components' => [['text' => '猫']],
        ]);
    }

    private function episodeWithDialogue(User $user): ContentEpisode
    {
        $episode = ContentEpisode::query()->forceCreate([
            'id' => (string) Str::uuid(), 'user_id' => $user->id,
            'convolab_user_id' => (string) Str::uuid(), 'title' => 'Episode', 'source_text' => 'Source',
            'target_language' => 'ja', 'native_language' => 'en', 'content_type' => 'dialogue',
            'auto_generate_audio' => true, 'status' => 'ready', 'is_sample_content' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $dialogue = ContentDialogue::query()->forceCreate([
            'id' => (string) Str::uuid(), 'episode_id' => $episode->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $speaker = ContentSpeaker::query()->forceCreate([
            'id' => (string) Str::uuid(), 'dialogue_id' => $dialogue->id, 'name' => 'Aki',
            'voice_id' => 'ja-JP-Neural2-B', 'proficiency' => 'native', 'tone' => 'casual',
        ]);
        ContentSentence::query()->forceCreate([
            'id' => (string) Str::uuid(), 'dialogue_id' => $dialogue->id, 'speaker_id' => $speaker->id,
            'sort_order' => 0, 'text' => '猫です。', 'translation' => 'It is a cat.',
            'metadata' => [], 'selected' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $episode;
    }

    private function linkEpisode(ContentCourse $course, ContentEpisode $episode, int $order): ContentEpisodeCourse
    {
        return ContentEpisodeCourse::query()->forceCreate([
            'id' => (string) Str::uuid(),
            'episode_id' => $episode->id,
            'convolab_course_id' => $course->id,
            'sort_order' => $order,
        ]);
    }
}
