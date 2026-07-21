<?php

namespace Tests\Feature\Content;

use App\Domain\Content\Actions\CreateContentCourseAction;
use App\Domain\Content\Actions\DeleteContentEpisodeAction;
use App\Domain\Content\Actions\UpdateContentEpisodeAction;
use App\Domain\Content\Data\CreateContentCourseData;
use App\Domain\Content\Data\UpdateContentEpisodeData;
use App\Domain\Content\Support\ContentSourceSystem;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ImportConvoLabEpisodesTest extends TestCase
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

    public function test_imports_dialogue_and_script_episode_graphs_from_real_source_table_names(): void
    {
        $targetUser = User::factory()->create(['email' => 'ada@example.com']);
        $ids = $this->seedSourceData();

        $exitCode = Artisan::call('content:import-convolab-episodes', [
            '--source-connection' => 'convolab_content_test',
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode, $output);
        $this->assertStringContainsString('Imported 2 rows into content_episodes.', $output);
        $this->assertStringContainsString('Imported 1 rows into content_audio_script_media.', $output);
        $this->assertStringContainsString('Imported 1 rows into content_courses.', $output);
        $this->assertStringContainsString('Imported 1 rows into content_course_core_items.', $output);

        $this->assertDatabaseHas('content_episodes', [
            'id' => $ids['dialogueEpisode'],
            'user_id' => $targetUser->id,
            'convolab_user_id' => $ids['user'],
            'source_system' => ContentSourceSystem::CONVOLAB,
            'content_type' => 'dialogue',
        ]);
        $this->assertDatabaseHas('content_audio_script_segments', [
            'id' => $ids['segment'],
            'image_media_id' => $ids['media'],
        ]);
        $this->assertDatabaseCount('content_audio_script_media', 1);
        $this->assertDatabaseHas('content_audio_script_media', [
            'id' => $ids['media'],
            'source_system' => ContentSourceSystem::CONVOLAB,
        ]);
        $this->assertDatabaseHas('content_episode_courses', [
            'episode_id' => $ids['dialogueEpisode'],
            'convolab_course_id' => $ids['course'],
            'source_system' => ContentSourceSystem::CONVOLAB,
        ]);
        $this->assertDatabaseHas('content_courses', [
            'id' => $ids['course'],
            'user_id' => $targetUser->id,
            'convolab_user_id' => $ids['user'],
            'source_system' => ContentSourceSystem::CONVOLAB,
            'status' => 'ready',
        ]);
        $this->assertDatabaseHas('content_course_core_items', [
            'id' => $ids['coreItem'],
            'course_id' => $ids['course'],
            'source_episode_id' => $ids['dialogueEpisode'],
        ]);
        $this->assertDatabaseHas('content_images', [
            'id' => $ids['image'],
            'episode_id' => $ids['dialogueEpisode'],
        ]);

        Sanctum::actingAs($targetUser);
        $this->withHeader('X-Convo-Lab-User-Id', $ids['user']);
        $this->getJson('/api/convolab/episodes')
            ->assertOk()
            ->assertJsonCount(2)
            ->assertJsonPath('0.id', $ids['scriptEpisode'])
            ->assertJsonPath('0.audioScript.segments.0.imageMedia.id', $ids['media'])
            ->assertJsonPath('0.audioScript.segments.0.imageMedia.sourceFilename', 'scene.png')
            ->assertJsonPath('0.audioScript.renders.0.numericSpeed', 0.85)
            ->assertJsonPath('1.dialogue.sentences.0.text', '猫です。')
            ->assertJsonPath('1.images.0.id', $ids['image'])
            ->assertJsonPath('1.images.0.createdAt', '2026-07-20T10:00:00.123Z');

        $this->getJson('/api/convolab/episodes/'.$ids['dialogueEpisode'])
            ->assertOk()
            ->assertJsonPath('courseEpisodes.0.courseId', $ids['course']);

        $this->getJson('/api/convolab/courses/'.$ids['course'])
            ->assertOk()
            ->assertJsonPath('id', $ids['course'])
            ->assertJsonPath('coreItems.0.id', $ids['coreItem'])
            ->assertJsonPath('courseEpisodes.0.episode.id', $ids['dialogueEpisode']);
    }

    public function test_refuses_a_non_empty_target_without_truncate(): void
    {
        User::factory()->create(['email' => 'ada@example.com']);
        $this->seedSourceData();

        $this->artisan('content:import-convolab-episodes', [
            '--source-connection' => 'convolab_content_test',
        ])->assertSuccessful();

        $this->artisan('content:import-convolab-episodes', [
            '--source-connection' => 'convolab_content_test',
        ])
            ->expectsOutputToContain('Target already contains imported content in [content_episodes]; rerun with --truncate.')
            ->assertFailed();

        $this->assertDatabaseCount('content_episodes', 2);
    }

    public function test_replacement_preserves_learning_owned_graph_and_refreshes_imported_roots(): void
    {
        $targetUser = User::factory()->create(['email' => 'ada@example.com']);
        $sourceIds = $this->seedSourceData();

        $this->artisan('content:import-convolab-episodes', [
            '--source-connection' => 'convolab_content_test',
        ])->assertSuccessful();

        $learningIds = $this->seedLearningOwnedGraph($targetUser, $sourceIds['user']);
        DB::connection('convolab_content_test')->table('Episode')
            ->where('id', $sourceIds['dialogueEpisode'])
            ->update(['title' => 'Refreshed source episode']);

        $this->artisan('content:import-convolab-episodes', [
            '--source-connection' => 'convolab_content_test',
            '--truncate' => true,
        ])
            ->expectsOutputToContain('Removed 2 previously imported rows from content_episodes.')
            ->assertSuccessful();

        $this->assertDatabaseHas('content_episodes', [
            'id' => $sourceIds['dialogueEpisode'],
            'title' => 'Refreshed source episode',
            'source_system' => ContentSourceSystem::CONVOLAB,
        ]);
        $this->assertDatabaseHas('content_episodes', [
            'id' => $learningIds['episode'],
            'title' => 'Learning-owned episode',
            'source_system' => ContentSourceSystem::LEARNING_OS,
        ]);
        $this->assertDatabaseHas('content_audio_script_media', [
            'id' => $learningIds['media'],
            'source_system' => ContentSourceSystem::LEARNING_OS,
        ]);
        $this->assertDatabaseHas('content_courses', [
            'id' => $learningIds['course'],
            'source_system' => ContentSourceSystem::LEARNING_OS,
        ]);
        $this->assertDatabaseHas('content_episode_courses', [
            'id' => $learningIds['courseEpisode'],
            'source_system' => ContentSourceSystem::LEARNING_OS,
        ]);
        $this->assertDatabaseCount('content_episodes', 3);
        $this->assertDatabaseCount('content_courses', 2);
        $this->assertDatabaseCount('content_audio_script_media', 2);
        $this->assertDatabaseCount('content_episode_courses', 2);
    }

    public function test_first_import_can_coexist_with_learning_owned_content_without_truncate(): void
    {
        $targetUser = User::factory()->create(['email' => 'ada@example.com']);
        $sourceIds = $this->seedSourceData();
        $learningIds = $this->seedLearningOwnedGraph($targetUser, $sourceIds['user']);

        $this->artisan('content:import-convolab-episodes', [
            '--source-connection' => 'convolab_content_test',
        ])->assertSuccessful();

        $this->assertDatabaseHas('content_episodes', ['id' => $learningIds['episode']]);
        $this->assertDatabaseHas('content_episodes', ['id' => $sourceIds['dialogueEpisode']]);
        $this->assertDatabaseCount('content_episodes', 3);
    }

    public function test_promoted_and_tombstoned_source_episodes_are_not_overwritten_or_resurrected(): void
    {
        $targetUser = User::factory()->create(['email' => 'ada@example.com']);
        $ids = $this->seedSourceData();

        $this->artisan('content:import-convolab-episodes', [
            '--source-connection' => 'convolab_content_test',
        ])->assertSuccessful();

        $promotedLinkId = (string) Str::uuid();
        DB::table('content_episode_courses')->insert([
            'id' => $promotedLinkId,
            'convolab_course_id' => $ids['course'],
            'episode_id' => $ids['scriptEpisode'],
            'source_system' => ContentSourceSystem::CONVOLAB,
            'sort_order' => 1,
        ]);

        $this->assertTrue(app(UpdateContentEpisodeAction::class)->handle(
            $targetUser->id,
            $ids['user'],
            $ids['scriptEpisode'],
            UpdateContentEpisodeData::fromInput(['title' => 'Learning-owned script']),
        ));
        $this->assertDatabaseHas('content_episode_courses', [
            'id' => $ids['courseEpisode'],
            'source_system' => ContentSourceSystem::CONVOLAB,
        ]);
        DB::connection('convolab_content_test')->table('CourseEpisode')
            ->where('courseId', $ids['course'])->delete();
        DB::connection('convolab_content_test')->table('CourseCoreItem')
            ->where('courseId', $ids['course'])->delete();
        DB::connection('convolab_content_test')->table('Course')
            ->where('id', $ids['course'])->delete();
        $this->assertTrue(app(DeleteContentEpisodeAction::class)->handle(
            $targetUser->id,
            $ids['user'],
            $ids['dialogueEpisode'],
        ));

        $this->artisan('content:import-convolab-episodes', [
            '--source-connection' => 'convolab_content_test',
            '--truncate' => true,
        ])
            ->expectsOutputToContain('Imported 0 rows into content_episodes.')
            ->expectsOutputToContain('Imported 0 rows into content_episode_courses.')
            ->assertSuccessful();

        $this->assertDatabaseHas('content_episodes', [
            'id' => $ids['scriptEpisode'],
            'title' => 'Learning-owned script',
            'source_system' => ContentSourceSystem::LEARNING_OS,
        ]);
        $this->assertDatabaseHas('content_audio_script_media', [
            'id' => $ids['media'],
            'source_system' => ContentSourceSystem::LEARNING_OS,
        ]);
        $this->assertDatabaseHas('content_episode_courses', [
            'id' => $promotedLinkId,
            'source_system' => ContentSourceSystem::LEARNING_OS,
        ]);
        $this->assertDatabaseHas('content_courses', [
            'id' => $ids['course'],
            'source_system' => ContentSourceSystem::LEARNING_OS,
        ]);
        $this->assertDatabaseMissing('content_episodes', ['id' => $ids['dialogueEpisode']]);
        $this->assertDatabaseHas('content_episode_tombstones', [
            'episode_id' => $ids['dialogueEpisode'],
            'user_id' => $targetUser->id,
            'convolab_user_id' => $ids['user'],
        ]);
        $this->assertDatabaseCount('content_episodes', 1);
    }

    public function test_replacement_reuses_promoted_media_still_referenced_by_an_imported_episode(): void
    {
        $targetUser = User::factory()->create(['email' => 'ada@example.com']);
        $ids = $this->seedSourceData();
        $sharedEpisodeId = (string) Str::uuid();
        $sharedScriptId = (string) Str::uuid();
        $sharedSegmentId = (string) Str::uuid();
        $created = '2026-07-20 10:00:00.123';
        $source = DB::connection('convolab_content_test');

        $source->table('Episode')->insert(
            $this->episodeRow($sharedEpisodeId, $ids['user'], 'script', $created),
        );
        $source->table('audio_scripts')->insert([
            'id' => $sharedScriptId, 'episodeId' => $sharedEpisodeId, 'status' => 'ready',
            'imageStatus' => 'ready', 'imageErrorMessage' => null, 'voiceId' => 'ja-JP-Neural2-B',
            'voiceProvider' => 'google', 'generationMetadataJson' => null, 'errorMessage' => null,
            'createdAt' => $created, 'updatedAt' => $created,
        ]);
        $source->table('audio_script_segments')->insert([
            'id' => $sharedSegmentId, 'scriptId' => $sharedScriptId, 'order' => 1,
            'text' => '猫です。', 'reading' => 'ねこです。', 'translation' => 'It is a cat.',
            'imagePrompt' => 'The same cat', 'imageStatus' => 'ready', 'imageErrorMessage' => null,
            'imageMediaId' => $ids['media'], 'imageGeneratedAt' => $created,
            'metadata' => null, 'createdAt' => $created, 'updatedAt' => $created,
        ]);

        $this->artisan('content:import-convolab-episodes', [
            '--source-connection' => 'convolab_content_test',
        ])->assertSuccessful();

        $this->assertTrue(app(UpdateContentEpisodeAction::class)->handle(
            $targetUser->id,
            $ids['user'],
            $ids['scriptEpisode'],
            UpdateContentEpisodeData::fromInput(['title' => 'Learning-owned script']),
        ));

        $this->artisan('content:import-convolab-episodes', [
            '--source-connection' => 'convolab_content_test',
            '--truncate' => true,
        ])->assertSuccessful();

        $this->assertDatabaseHas('content_audio_script_media', [
            'id' => $ids['media'],
            'user_id' => $targetUser->id,
            'source_system' => ContentSourceSystem::LEARNING_OS,
        ]);
        $this->assertDatabaseHas('content_audio_script_segments', [
            'id' => $sharedSegmentId,
            'image_media_id' => $ids['media'],
        ]);
        $this->assertDatabaseHas('content_episodes', [
            'id' => $sharedEpisodeId,
            'source_system' => ContentSourceSystem::CONVOLAB,
        ]);
        $this->assertDatabaseCount('content_audio_script_media', 1);
    }

    public function test_promoted_course_refreshes_untouched_episode_links_without_replacing_core_items(): void
    {
        $targetUser = User::factory()->create(['email' => 'ada@example.com']);
        $ids = $this->seedSourceData();
        $scriptCourseEpisodeId = (string) Str::uuid();
        DB::connection('convolab_content_test')->table('CourseEpisode')->insert([
            'id' => $scriptCourseEpisodeId,
            'courseId' => $ids['course'],
            'episodeId' => $ids['scriptEpisode'],
            'order' => 4,
        ]);

        $this->artisan('content:import-convolab-episodes', [
            '--source-connection' => 'convolab_content_test',
        ])->assertSuccessful();

        $this->assertTrue(app(UpdateContentEpisodeAction::class)->handle(
            $targetUser->id,
            $ids['user'],
            $ids['scriptEpisode'],
            UpdateContentEpisodeData::fromInput(['title' => 'Learning-owned script']),
        ));
        DB::table('content_course_core_items')
            ->where('id', $ids['coreItem'])
            ->update(['translation_l1' => 'preserved cat']);

        $this->assertDatabaseHas('content_episode_courses', [
            'id' => $scriptCourseEpisodeId,
            'source_system' => ContentSourceSystem::LEARNING_OS,
        ]);
        $this->assertDatabaseHas('content_episode_courses', [
            'id' => $ids['courseEpisode'],
            'source_system' => ContentSourceSystem::CONVOLAB,
        ]);

        $this->artisan('content:import-convolab-episodes', [
            '--source-connection' => 'convolab_content_test',
            '--truncate' => true,
        ])->assertSuccessful();

        $this->assertDatabaseHas('content_courses', [
            'id' => $ids['course'],
            'source_system' => ContentSourceSystem::LEARNING_OS,
        ]);
        $this->assertDatabaseHas('content_episode_courses', [
            'id' => $scriptCourseEpisodeId,
            'source_system' => ContentSourceSystem::LEARNING_OS,
        ]);
        $this->assertDatabaseHas('content_episode_courses', [
            'id' => $ids['courseEpisode'],
            'source_system' => ContentSourceSystem::CONVOLAB,
        ]);
        $this->assertDatabaseHas('content_course_core_items', [
            'id' => $ids['coreItem'],
            'translation_l1' => 'preserved cat',
        ]);
        $this->assertDatabaseCount('content_episode_courses', 2);
        $this->assertDatabaseCount('content_course_core_items', 1);
    }

    public function test_course_created_from_an_imported_episode_survives_replacement_imports(): void
    {
        $targetUser = User::factory()->create(['email' => 'ada@example.com']);
        $ids = $this->seedSourceData();

        $this->artisan('content:import-convolab-episodes', [
            '--source-connection' => 'convolab_content_test',
        ])->assertSuccessful();

        $result = app(CreateContentCourseAction::class)->handle(CreateContentCourseData::fromInput(
            $targetUser->id,
            $ids['user'],
            [
                'title' => 'Learning Course',
                'description' => 'Keep this Course.',
                'episodeIds' => [$ids['dialogueEpisode']],
                'nativeLanguage' => 'en',
                'targetLanguage' => 'ja',
            ],
        ));
        $course = $result->course;
        $this->assertNotNull($course);
        $linkId = $course->courseEpisodes()->sole()->id;

        $this->artisan('content:import-convolab-episodes', [
            '--source-connection' => 'convolab_content_test',
            '--truncate' => true,
        ])->assertSuccessful();

        $this->assertDatabaseHas('content_episodes', [
            'id' => $ids['dialogueEpisode'],
            'source_system' => ContentSourceSystem::LEARNING_OS,
        ]);
        $this->assertDatabaseHas('content_courses', [
            'id' => $course->id,
            'source_system' => ContentSourceSystem::LEARNING_OS,
        ]);
        $this->assertDatabaseHas('content_episode_courses', [
            'id' => $linkId,
            'episode_id' => $ids['dialogueEpisode'],
            'source_system' => ContentSourceSystem::LEARNING_OS,
        ]);
        $this->assertDatabaseHas('content_episode_courses', [
            'id' => $ids['courseEpisode'],
            'episode_id' => $ids['dialogueEpisode'],
            'source_system' => ContentSourceSystem::CONVOLAB,
        ]);
    }

    public function test_replacement_and_preserved_content_roll_back_together_on_late_import_failure(): void
    {
        $targetUser = User::factory()->create(['email' => 'ada@example.com']);
        $ids = $this->seedSourceData();

        $this->artisan('content:import-convolab-episodes', [
            '--source-connection' => 'convolab_content_test',
        ])->assertSuccessful();
        $learningIds = $this->seedLearningOwnedGraph($targetUser, $ids['user']);
        DB::table('content_episodes')->where('id', $ids['dialogueEpisode'])
            ->update(['title' => 'Existing imported title']);
        DB::connection('convolab_content_test')->table('Sentence')->where('id', $ids['sentence'])
            ->update(['metadata' => null]);

        $this->artisan('content:import-convolab-episodes', [
            '--source-connection' => 'convolab_content_test',
            '--truncate' => true,
        ])
            ->expectsOutputToContain("Sentence [{$ids['sentence']}] metadata must not be null.")
            ->assertFailed();

        $this->assertDatabaseHas('content_episodes', [
            'id' => $ids['dialogueEpisode'],
            'title' => 'Existing imported title',
            'source_system' => ContentSourceSystem::CONVOLAB,
        ]);
        $this->assertDatabaseHas('content_episodes', [
            'id' => $learningIds['episode'],
            'source_system' => ContentSourceSystem::LEARNING_OS,
        ]);
        $this->assertDatabaseCount('content_episodes', 3);
    }

    public function test_rejects_the_target_database_as_the_source(): void
    {
        $this->artisan('content:import-convolab-episodes', [
            '--source-connection' => DB::getDefaultConnection(),
        ])
            ->expectsOutputToContain('Source and target databases must differ.')
            ->assertFailed();
    }

    public function test_rejects_duplicate_normalized_source_emails(): void
    {
        User::factory()->create(['email' => 'ada@example.com']);
        $this->seedSourceData();
        DB::connection('convolab_content_test')->table('User')->insert([
            'id' => (string) Str::uuid(),
            'email' => ' ADA@example.com ',
        ]);

        $this->artisan('content:import-convolab-episodes', [
            '--source-connection' => 'convolab_content_test',
        ])
            ->expectsOutputToContain('Source contains duplicate normalized user email [ada@example.com].')
            ->assertFailed();

        $this->assertDatabaseCount('content_episodes', 0);
    }

    public function test_rejects_unexpected_nulls_in_source_required_fields_with_context(): void
    {
        User::factory()->create(['email' => 'ada@example.com']);
        $ids = $this->seedSourceData();
        DB::connection('convolab_content_test')->table('Sentence')
            ->where('id', $ids['sentence'])
            ->update(['metadata' => null]);

        $this->artisan('content:import-convolab-episodes', [
            '--source-connection' => 'convolab_content_test',
        ])
            ->expectsOutputToContain("Sentence [{$ids['sentence']}] metadata must not be null.")
            ->assertFailed();

        $this->assertDatabaseCount('content_episodes', 0);
    }

    public function test_rejects_unsupported_source_content_types_instead_of_hiding_imported_rows(): void
    {
        User::factory()->create(['email' => 'ada@example.com']);
        $ids = $this->seedSourceData();
        DB::connection('convolab_content_test')->table('Episode')
            ->where('id', $ids['dialogueEpisode'])
            ->update(['contentType' => 'quiz']);

        $this->artisan('content:import-convolab-episodes', [
            '--source-connection' => 'convolab_content_test',
        ])
            ->expectsOutputToContain("Episode [{$ids['dialogueEpisode']}] has unsupported content type [quiz].")
            ->assertFailed();

        $this->assertDatabaseCount('content_episodes', 0);
    }

    public function test_production_truncate_requires_the_exact_target_confirmation(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');
        User::factory()->create(['email' => 'ada@example.com']);
        $this->seedSourceData();
        $targetDatabase = DB::connection()->getDatabaseName();

        $this->artisan('content:import-convolab-episodes', [
            '--source-connection' => 'convolab_content_test',
            '--truncate' => true,
            '--allow-production' => true,
        ])
            ->expectsOutputToContain(
                "Production replacement requires --production-truncate-confirmation=\"TRUNCATE {$targetDatabase}\".",
            )
            ->assertFailed();

        $this->assertDatabaseCount('content_episodes', 0);
    }

    /** @return array{episode: string, media: string, course: string, courseEpisode: string} */
    private function seedLearningOwnedGraph(User $user, string $convoLabUserId): array
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
    private function seedSourceData(): array
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

        $source->table('User')->insert(['id' => $ids['user'], 'email' => 'Ada@Example.com']);
        $source->table('Episode')->insert([
            $this->episodeRow($ids['dialogueEpisode'], $ids['user'], 'Dialogue', $created),
            $this->episodeRow($ids['scriptEpisode'], $ids['user'], 'script', '2026-07-20 11:00:00.456'),
        ]);
        $source->table('Dialogue')->insert([
            'id' => $ids['dialogue'],
            'episodeId' => $ids['dialogueEpisode'],
            'createdAt' => $created,
            'updatedAt' => $created,
        ]);
        $source->table('Speaker')->insert([
            'id' => $ids['speaker'], 'dialogueId' => $ids['dialogue'], 'name' => 'Aki',
            'voiceId' => 'ja-JP-Neural2-B', 'voiceProvider' => 'google',
            'proficiency' => 'beginner', 'tone' => 'polite', 'gender' => 'female',
            'color' => 'cyan', 'avatarUrl' => '/api/avatars/voices/aki.jpg',
        ]);
        $source->table('Sentence')->insert([
            'id' => $ids['sentence'], 'dialogueId' => $ids['dialogue'], 'speakerId' => $ids['speaker'],
            'order' => 1, 'text' => '猫です。', 'translation' => 'It is a cat.',
            'metadata' => json_encode(['japanese' => ['kanji' => '猫です。']]),
            'audioUrl' => null, 'startTime' => 0, 'endTime' => 800,
            'startTime_0_7' => null, 'endTime_0_7' => null,
            'startTime_0_85' => null, 'endTime_0_85' => null,
            'startTime_1_0' => null, 'endTime_1_0' => null,
            'variations' => json_encode(['猫だ。']), 'selected' => true,
            'createdAt' => $created, 'updatedAt' => $created,
        ]);
        $source->table('Image')->insert([
            'id' => $ids['image'], 'episodeId' => $ids['dialogueEpisode'],
            'url' => '/uploads/episode-images/dialogue.png', 'prompt' => 'A cat', 'order' => 1,
            'sentenceStartId' => $ids['sentence'], 'sentenceEndId' => $ids['sentence'],
            'createdAt' => $created,
        ]);
        $source->table('audio_scripts')->insert([
            'id' => $ids['script'], 'episodeId' => $ids['scriptEpisode'], 'status' => 'ready',
            'imageStatus' => 'ready', 'imageErrorMessage' => null, 'voiceId' => 'ja-JP-Neural2-B',
            'voiceProvider' => 'google', 'generationMetadataJson' => json_encode(['model' => 'test']),
            'errorMessage' => null, 'createdAt' => $created, 'updatedAt' => $created,
        ]);
        $source->table('study_media')->insert([
            [
                'id' => $ids['media'], 'userId' => $ids['user'], 'sourceKind' => 'generated',
                'sourceFilename' => 'scene.png', 'normalizedFilename' => 'scene.png', 'mediaKind' => 'image',
                'contentType' => 'image/png', 'storagePath' => 'episode-images/scene.png',
                'publicUrl' => '/uploads/episode-images/scene.png', 'createdAt' => $created, 'updatedAt' => $created,
            ],
            [
                'id' => $ids['unreferencedMedia'], 'userId' => $ids['user'], 'sourceKind' => 'anki_import',
                'sourceFilename' => 'card.png', 'normalizedFilename' => 'card.png', 'mediaKind' => 'image',
                'contentType' => 'image/png', 'storagePath' => 'study-media/card.png',
                'publicUrl' => '/uploads/study-media/card.png', 'createdAt' => $created, 'updatedAt' => $created,
            ],
        ]);
        $source->table('audio_script_segments')->insert([
            'id' => $ids['segment'], 'scriptId' => $ids['script'], 'order' => 1,
            'text' => '猫です。', 'reading' => 'ねこです。', 'translation' => 'It is a cat.',
            'imagePrompt' => 'A cat', 'imageStatus' => 'ready', 'imageErrorMessage' => null,
            'imageMediaId' => $ids['media'], 'imageGeneratedAt' => $created,
            'metadata' => json_encode(['scene' => 1]), 'createdAt' => $created, 'updatedAt' => $created,
        ]);
        $source->table('audio_script_renders')->insert([
            'id' => $ids['render'], 'scriptId' => $ids['script'], 'speed' => '0.85',
            'numericSpeed' => 0.85, 'status' => 'ready', 'audioUrl' => '/audio/script.mp3',
            'timingData' => json_encode([['startTime' => 0, 'endTime' => 800]]),
            'approxDurationSeconds' => 0.8, 'errorMessage' => null,
            'createdAt' => $created, 'updatedAt' => $created,
        ]);
        $source->table('Course')->insert([
            'id' => $ids['course'], 'userId' => $ids['user'], 'title' => 'Cat course',
            'description' => 'Learn about cats.', 'status' => 'ready', 'isSampleContent' => false,
            'isTestCourse' => false, 'nativeLanguage' => 'en', 'targetLanguage' => 'ja',
            'maxLessonDurationMinutes' => 30, 'l1VoiceId' => 'en-US-Neural2-J',
            'l1VoiceProvider' => 'google', 'jlptLevel' => 'N5', 'speaker1Gender' => 'male',
            'speaker2Gender' => 'female', 'speaker1VoiceId' => 'ja-JP-Neural2-B',
            'speaker1VoiceProvider' => 'google', 'speaker2VoiceId' => 'ja-JP-Neural2-C',
            'speaker2VoiceProvider' => 'google',
            'scriptJson' => json_encode([['_pipelineStage' => 'complete']]),
            'scriptUnitsJson' => json_encode([['type' => 'pause', 'seconds' => 1]]),
            'approxDurationSeconds' => 120, 'audioUrl' => '/audio/course.mp3',
            'timingData' => json_encode([['unitIndex' => 0, 'startTime' => 0, 'endTime' => 1]]),
            'createdAt' => $created, 'updatedAt' => $created,
        ]);
        $source->table('CourseCoreItem')->insert([
            'id' => $ids['coreItem'], 'courseId' => $ids['course'], 'textL2' => '猫',
            'readingL2' => 'ねこ', 'translationL1' => 'cat', 'complexityScore' => 1.25,
            'sourceEpisodeId' => $ids['dialogueEpisode'], 'sourceSentenceId' => $ids['sentence'],
            'sourceUnitIndex' => 2, 'components' => json_encode([['text' => '猫']]),
        ]);
        $source->table('CourseEpisode')->insert([
            'id' => $ids['courseEpisode'], 'courseId' => $ids['course'],
            'episodeId' => $ids['dialogueEpisode'], 'order' => 3,
        ]);

        return $ids;
    }

    /** @return array<string, mixed> */
    private function episodeRow(string $id, string $userId, string $contentType, string $updatedAt): array
    {
        return [
            'id' => $id, 'userId' => $userId, 'title' => ucfirst($contentType).' episode',
            'sourceText' => 'Source text', 'targetLanguage' => 'ja', 'nativeLanguage' => 'en',
            'contentType' => $contentType, 'jlptLevel' => 'N5', 'autoGenerateAudio' => true,
            'status' => 'ready', 'isSampleContent' => false, 'audioUrl' => null,
            'audioSpeed' => 'medium', 'audioUrl_0_7' => null, 'audioUrl_0_85' => null,
            'audioUrl_1_0' => null, 'createdAt' => '2026-07-20 10:00:00.123', 'updatedAt' => $updatedAt,
        ];
    }

    private function createSourceSchema(): void
    {
        $schema = Schema::connection('convolab_content_test');
        $schema->create('User', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('email');
        });
        $schema->create('Episode', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('userId');
            $table->string('title');
            $table->text('sourceText');
            $table->string('targetLanguage');
            $table->string('nativeLanguage');
            $table->string('contentType');
            $table->string('jlptLevel')->nullable();
            $table->boolean('autoGenerateAudio');
            $table->string('status');
            $table->boolean('isSampleContent');
            $table->text('audioUrl')->nullable();
            $table->string('audioSpeed')->nullable();
            $table->text('audioUrl_0_7')->nullable();
            $table->text('audioUrl_0_85')->nullable();
            $table->text('audioUrl_1_0')->nullable();
            $table->dateTime('createdAt');
            $table->dateTime('updatedAt');
        });
        $schema->create('Dialogue', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('episodeId');
            $table->dateTime('createdAt');
            $table->dateTime('updatedAt');
        });
        $schema->create('Speaker', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('dialogueId');
            $table->string('name');
            $table->string('voiceId');
            $table->string('voiceProvider')->nullable();
            $table->string('proficiency');
            $table->string('tone');
            $table->string('gender')->nullable();
            $table->string('color')->nullable();
            $table->text('avatarUrl')->nullable();
        });
        $schema->create('Sentence', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('dialogueId');
            $table->string('speakerId');
            $table->integer('order');
            $table->text('text');
            $table->text('translation');
            $table->json('metadata')->nullable();
            $table->text('audioUrl')->nullable();
            $table->integer('startTime')->nullable();
            $table->integer('endTime')->nullable();
            foreach (['startTime_0_7', 'endTime_0_7', 'startTime_0_85', 'endTime_0_85', 'startTime_1_0', 'endTime_1_0'] as $column) {
                $table->integer($column)->nullable();
            }
            $table->json('variations')->nullable();
            $table->boolean('selected');
            $table->dateTime('createdAt');
            $table->dateTime('updatedAt');
        });
        $schema->create('Image', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('episodeId');
            $table->text('url')->nullable();
            $table->text('prompt')->nullable();
            $table->integer('order');
            $table->string('sentenceStartId')->nullable();
            $table->string('sentenceEndId')->nullable();
            $table->dateTime('createdAt');
        });
        $schema->create('audio_scripts', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('episodeId');
            $table->string('status');
            $table->string('imageStatus');
            $table->text('imageErrorMessage')->nullable();
            $table->string('voiceId');
            $table->string('voiceProvider');
            $table->json('generationMetadataJson')->nullable();
            $table->text('errorMessage')->nullable();
            $table->dateTime('createdAt');
            $table->dateTime('updatedAt');
        });
        $schema->create('study_media', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('userId');
            $table->string('sourceKind');
            $table->string('sourceFilename');
            $table->string('normalizedFilename');
            $table->string('mediaKind');
            $table->string('contentType')->nullable();
            $table->text('storagePath')->nullable();
            $table->text('publicUrl')->nullable();
            $table->dateTime('createdAt');
            $table->dateTime('updatedAt');
        });
        $schema->create('audio_script_segments', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('scriptId');
            $table->integer('order');
            $table->text('text');
            $table->text('reading')->nullable();
            $table->text('translation');
            $table->text('imagePrompt')->nullable();
            $table->string('imageStatus');
            $table->text('imageErrorMessage')->nullable();
            $table->string('imageMediaId')->nullable();
            $table->dateTime('imageGeneratedAt')->nullable();
            $table->json('metadata')->nullable();
            $table->dateTime('createdAt');
            $table->dateTime('updatedAt');
        });
        $schema->create('audio_script_renders', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('scriptId');
            $table->string('speed');
            $table->double('numericSpeed');
            $table->string('status');
            $table->text('audioUrl')->nullable();
            $table->json('timingData')->nullable();
            $table->double('approxDurationSeconds')->nullable();
            $table->text('errorMessage')->nullable();
            $table->dateTime('createdAt');
            $table->dateTime('updatedAt');
        });
        $schema->create('Course', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('userId');
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status');
            $table->boolean('isSampleContent');
            $table->boolean('isTestCourse');
            $table->string('nativeLanguage');
            $table->string('targetLanguage');
            $table->integer('maxLessonDurationMinutes');
            $table->string('l1VoiceId');
            $table->string('l1VoiceProvider')->nullable();
            $table->string('jlptLevel')->nullable();
            $table->string('speaker1Gender');
            $table->string('speaker2Gender');
            $table->string('speaker1VoiceId')->nullable();
            $table->string('speaker1VoiceProvider')->nullable();
            $table->string('speaker2VoiceId')->nullable();
            $table->string('speaker2VoiceProvider')->nullable();
            $table->json('scriptJson')->nullable();
            $table->json('scriptUnitsJson')->nullable();
            $table->integer('approxDurationSeconds')->nullable();
            $table->text('audioUrl')->nullable();
            $table->json('timingData')->nullable();
            $table->dateTime('createdAt');
            $table->dateTime('updatedAt');
        });
        $schema->create('CourseCoreItem', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('courseId');
            $table->text('textL2');
            $table->text('readingL2')->nullable();
            $table->text('translationL1');
            $table->double('complexityScore');
            $table->string('sourceEpisodeId')->nullable();
            $table->string('sourceSentenceId')->nullable();
            $table->integer('sourceUnitIndex')->nullable();
            $table->json('components')->nullable();
        });
        $schema->create('CourseEpisode', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('courseId');
            $table->string('episodeId');
            $table->integer('order');
        });
    }
}
