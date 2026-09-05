<?php

namespace Tests\Feature\Content;

use App\Domain\Content\Support\ContentSourceSystem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\Content\ConvoLabAudioScriptRendersSourceTable;
use Tests\Support\Content\ConvoLabAudioScriptSegmentsSourceTable;
use Tests\Support\Content\ConvoLabAudioScriptsSourceTable;
use Tests\Support\Content\ConvoLabCourseCoreItemSourceTable;
use Tests\Support\Content\ConvoLabCourseEpisodeSourceTable;
use Tests\Support\Content\ConvoLabCourseSourceTable;
use Tests\Support\Content\ConvoLabDialogueSourceTable;
use Tests\Support\Content\ConvoLabEpisodeSourceTable;
use Tests\Support\Content\ConvoLabImageSourceTable;
use Tests\Support\Content\ConvoLabSentenceSourceTable;
use Tests\Support\Content\ConvoLabSpeakerSourceTable;
use Tests\Support\Content\ConvoLabStudyMediaSourceTable;
use Tests\Support\Content\ConvoLabUserSourceTable;
use Tests\TestCase;

abstract class ImportConvoLabEpisodesTestCase extends TestCase
{
    use RefreshDatabase;

    private string $sourceDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sourceDatabase = storage_path('framework/testing/convolab-content-'.uniqid().'.sqlite');
        touch($this->sourceDatabase);
        config([
            'database.connections.convolab_content_test' => [
                'driver' => 'sqlite',
                'database' => $this->sourceDatabase,
                'prefix' => '',
                'foreign_key_constraints' => false,
            ],
        ]);
        DB::purge('convolab_content_test');
        $this->createSourceSchema();
    }

    protected function tearDown(): void
    {
        DB::purge('convolab_content_test');

        if (isset($this->sourceDatabase) && is_file($this->sourceDatabase)) {
            unlink($this->sourceDatabase);
        }

        parent::tearDown();
    }

    /** @return array{episode: string, media: string, course: string, courseEpisode: string} */
    protected function seedLearningOwnedGraph(User $user, string $convoLabUserId): array
    {
        $ids = [
            'episode' => (string) Str::uuid(),
            'script' => (string) Str::uuid(),
            'media' => (string) Str::uuid(),
            'segment' => (string) Str::uuid(),
            'course' => (string) Str::uuid(),
            'courseEpisode' => (string) Str::uuid(),
        ];
        $now = now();

        DB::table('content_episodes')->insert([
            'id' => $ids['episode'], 'user_id' => $user->id,
            'convolab_user_id' => $convoLabUserId,
            'source_system' => ContentSourceSystem::LEARNING_OS,
            'title' => 'Learning-owned episode', 'source_text' => 'Learning-owned source text',
            'target_language' => 'ja', 'native_language' => 'en', 'content_type' => 'script',
            'auto_generate_audio' => true, 'status' => 'ready', 'is_sample_content' => false,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('content_audio_scripts')->insert([
            'id' => $ids['script'], 'episode_id' => $ids['episode'], 'status' => 'ready',
            'image_status' => 'ready', 'voice_id' => 'ja-JP-Neural2-B',
            'voice_provider' => 'google', 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('content_audio_script_media')->insert([
            'id' => $ids['media'], 'user_id' => $user->id, 'source_kind' => 'generated',
            'source_system' => ContentSourceSystem::LEARNING_OS,
            'source_filename' => 'learning.png', 'normalized_filename' => 'learning.png',
            'media_kind' => 'image', 'content_type' => 'image/png',
            'storage_path' => 'learning/learning.png', 'public_url' => '/learning/learning.png',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('content_audio_script_segments')->insert([
            'id' => $ids['segment'], 'script_id' => $ids['script'], 'sort_order' => 0,
            'text' => '猫です。', 'translation' => 'It is a cat.', 'image_status' => 'ready',
            'image_media_id' => $ids['media'], 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('content_courses')->insert([
            'id' => $ids['course'], 'user_id' => $user->id,
            'convolab_user_id' => $convoLabUserId,
            'source_system' => ContentSourceSystem::LEARNING_OS,
            'title' => 'Learning-owned course', 'status' => 'ready',
            'is_sample_content' => false, 'is_test_course' => false,
            'native_language' => 'en', 'target_language' => 'ja',
            'max_lesson_duration_minutes' => 30, 'l1_voice_id' => 'en-US-Neural2-J',
            'speaker1_gender' => 'male', 'speaker2_gender' => 'female',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('content_episode_courses')->insert([
            'id' => $ids['courseEpisode'], 'episode_id' => $ids['episode'],
            'convolab_course_id' => $ids['course'], 'sort_order' => 0,
            'source_system' => ContentSourceSystem::LEARNING_OS,
        ]);

        return [
            'episode' => $ids['episode'],
            'media' => $ids['media'],
            'course' => $ids['course'],
            'courseEpisode' => $ids['courseEpisode'],
        ];
    }

    /** @return array<string, string> */
    protected function seedSourceData(): array
    {
        $ids = [
            'user' => (string) Str::uuid(),
            'dialogueEpisode' => (string) Str::uuid(),
            'scriptEpisode' => (string) Str::uuid(),
            'dialogue' => (string) Str::uuid(),
            'speaker' => (string) Str::uuid(),
            'sentence' => (string) Str::uuid(),
            'image' => (string) Str::uuid(),
            'script' => (string) Str::uuid(),
            'segment' => (string) Str::uuid(),
            'media' => (string) Str::uuid(),
            'unreferencedMedia' => (string) Str::uuid(),
            'render' => (string) Str::uuid(),
            'courseEpisode' => (string) Str::uuid(),
            'course' => (string) Str::uuid(),
            'coreItem' => (string) Str::uuid(),
        ];
        $source = DB::connection('convolab_content_test');
        $created = '2026-07-20 10:00:00.123';

        ConvoLabUserSourceTable::seed($source, $ids, $created);
        ConvoLabEpisodeSourceTable::seed($source, $ids, $created);
        ConvoLabDialogueSourceTable::seed($source, $ids, $created);
        ConvoLabSpeakerSourceTable::seed($source, $ids, $created);
        ConvoLabSentenceSourceTable::seed($source, $ids, $created);
        ConvoLabImageSourceTable::seed($source, $ids, $created);
        ConvoLabAudioScriptsSourceTable::seed($source, $ids, $created);
        ConvoLabStudyMediaSourceTable::seed($source, $ids, $created);
        ConvoLabAudioScriptSegmentsSourceTable::seed($source, $ids, $created);
        ConvoLabAudioScriptRendersSourceTable::seed($source, $ids, $created);
        ConvoLabCourseSourceTable::seed($source, $ids, $created);
        ConvoLabCourseCoreItemSourceTable::seed($source, $ids, $created);
        ConvoLabCourseEpisodeSourceTable::seed($source, $ids, $created);

        return $ids;
    }

    /** @return array<string, mixed> */
    protected function episodeRow(string $id, string $userId, string $contentType, string $updatedAt): array
    {
        return ConvoLabEpisodeSourceTable::row($id, $userId, $contentType, $updatedAt);
    }

    protected function createSourceSchema(): void
    {
        $schema = Schema::connection('convolab_content_test');

        ConvoLabUserSourceTable::create($schema);
        ConvoLabEpisodeSourceTable::create($schema);
        ConvoLabDialogueSourceTable::create($schema);
        ConvoLabSpeakerSourceTable::create($schema);
        ConvoLabSentenceSourceTable::create($schema);
        ConvoLabImageSourceTable::create($schema);
        ConvoLabAudioScriptsSourceTable::create($schema);
        ConvoLabStudyMediaSourceTable::create($schema);
        ConvoLabAudioScriptSegmentsSourceTable::create($schema);
        ConvoLabAudioScriptRendersSourceTable::create($schema);
        ConvoLabCourseSourceTable::create($schema);
        ConvoLabCourseCoreItemSourceTable::create($schema);
        ConvoLabCourseEpisodeSourceTable::create($schema);
    }
}
