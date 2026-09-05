<?php

namespace Tests\Feature\Content;

use App\Domain\Content\Actions\DeleteContentCourseAction;
use App\Domain\Content\Actions\DeleteContentEpisodeAction;
use App\Domain\Content\Actions\UpdateContentCourseAction;
use App\Domain\Content\Actions\UpdateContentEpisodeAction;
use App\Domain\Content\Data\UpdateContentCourseData;
use App\Domain\Content\Data\UpdateContentEpisodeData;
use App\Domain\Content\Support\ContentSourceSystem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ImportConvoLabEpisodesPromotionTest extends ImportConvoLabEpisodesTestCase
{
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

    public function test_promoted_and_tombstoned_source_courses_are_not_overwritten_or_resurrected(): void
    {
        $targetUser = User::factory()->create(['email' => 'ada@example.com']);
        $ids = $this->seedSourceData();

        $this->artisan('content:import-convolab-episodes', [
            '--source-connection' => 'convolab_content_test',
        ])->assertSuccessful();

        $this->assertTrue(app(UpdateContentCourseAction::class)->handle(
            $targetUser->id,
            $ids['user'],
            $ids['course'],
            UpdateContentCourseData::fromInput(['title' => 'Learning-owned Course']),
        ));

        $this->artisan('content:import-convolab-episodes', [
            '--source-connection' => 'convolab_content_test',
            '--truncate' => true,
        ])->assertSuccessful();

        $this->assertDatabaseHas('content_courses', [
            'id' => $ids['course'],
            'title' => 'Learning-owned Course',
            'source_system' => ContentSourceSystem::LEARNING_OS,
        ]);
        $this->assertDatabaseHas('content_episode_courses', [
            'id' => $ids['courseEpisode'],
            'convolab_course_id' => $ids['course'],
        ]);

        $this->assertTrue(app(DeleteContentCourseAction::class)->handle(
            $targetUser->id,
            $ids['user'],
            $ids['course'],
        ));
        $this->assertDatabaseMissing('content_courses', ['id' => $ids['course']]);

        $this->artisan('content:import-convolab-episodes', [
            '--source-connection' => 'convolab_content_test',
            '--truncate' => true,
        ])->assertSuccessful();

        $this->assertDatabaseMissing('content_courses', ['id' => $ids['course']]);
        $this->assertDatabaseMissing('content_episode_courses', [
            'convolab_course_id' => $ids['course'],
        ]);
        $this->assertDatabaseMissing('content_course_core_items', [
            'course_id' => $ids['course'],
        ]);
        $this->assertDatabaseHas('content_course_tombstones', [
            'course_id' => $ids['course'],
            'user_id' => $targetUser->id,
            'convolab_user_id' => $ids['user'],
        ]);
        $this->assertSame(
            1,
            DB::connection('convolab_content_test')->table('Course')
                ->where('id', $ids['course'])
                ->count(),
        );
    }
}
