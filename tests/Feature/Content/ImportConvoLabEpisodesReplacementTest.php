<?php

namespace Tests\Feature\Content;

use App\Domain\Content\Actions\CreateContentCourseAction;
use App\Domain\Content\Actions\UpdateContentEpisodeAction;
use App\Domain\Content\Data\CreateContentCourseData;
use App\Domain\Content\Data\UpdateContentEpisodeData;
use App\Domain\Content\Support\ContentSourceSystem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ImportConvoLabEpisodesReplacementTest extends ImportConvoLabEpisodesTestCase
{
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
}
