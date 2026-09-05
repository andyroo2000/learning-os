<?php

namespace Tests\Feature\Content;

use App\Domain\Content\Support\ContentSourceSystem;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;

class ImportConvoLabEpisodesTest extends ImportConvoLabEpisodesTestCase
{
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

        $this->asConvoLabBrowser($targetUser, convoLabUserId: $ids['user'])
            ->getJson('/api/convolab/episodes')
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
}
